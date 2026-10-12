<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Loyalty\Services\PointsPolicy;
use App\Modules\Loyalty\Services\PointsService;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «نقاطي».
 *
 * Reached through the signed-in customer and never by key, like the wallet:
 * there is no id here to guess at. Reading creates nothing — a customer who has
 * never earned a point is answered with zero, not given an account.
 */
class PointsController extends Controller
{
    public function __construct(
        private readonly PointsService $points,
        private readonly PointsPolicy $policy,
    ) {}

    /**
     * The balance, what it is worth, the soonest expiry, and the programme's
     * rules for the screen that explains them.
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $request->user();
        $balance = $this->points->available($customer);
        $next = $this->points->nextExpiry($customer);

        return successReturnData([
            'enabled' => $this->policy->enabled(),
            // What can be spent now: a lapsed point is never counted, whenever
            // the nightly write-off last ran.
            'balance' => $balance,
            'balance_value' => round($balance * $this->policy->pointValue(), 2),
            'currency' => appCurrency(),
            // «30 نقطة تنتهي يوم 12 نوفمبر» — null when nothing is due to lapse.
            'next_expiry' => $next ? [
                'points' => $next['points'],
                'date' => humanDate($next['expires_at'], 'j M Y'),
                'date_iso' => isoDate($next['expires_at']),
            ] : null,
            'rules' => $this->policy->summary(),
        ]);
    }

    /**
     * The history, with the app's tabs: الكل / المكتسبة / المستخدمة / المنتهية.
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = PointTransaction::where('user_id', $request->user()->id)
            // The order a movement belongs to, for «أوردر #10244» on the row —
            // through the leg for hangers. One query per kind, not per row.
            ->with(['source' => fn (MorphTo $morph) => $morph->morphWith([
                OrderTask::class => ['order:id,code'],
            ])]);

        $group = $request->get('group');

        if ($group && $group !== 'all') {
            $reasons = array_filter(PointReason::cases(), fn (PointReason $reason) => $reason->group() === $group);

            $query->whereIn('reason', array_map(fn (PointReason $reason) => $reason->value, $reasons));
        }

        // Bounded both ways: a page size of zero or less would reach
        // paginate() as no limit at all and hand over the whole ledger.
        $rows = $query->latest('id')->paginate(max(min((int) $request->get('per_page', 20), 50), 1));

        $payload = [];

        foreach ($rows->items() as $row) {
            $payload[] = [
                'id' => $row->id,
                // «+10» / «-45» — signed for display, unsigned in store.
                'points' => $row->signedPoints(),
                'direction' => $row->direction,
                'reason' => $row->reason->value,
                'reason_label' => __($row->reason->label()),
                'group' => $row->reason->group(),
                'note' => $row->note,
                'order_code' => $this->orderCode($row),
                'balance_after' => $row->balance_after,
                // On points added: when they lapse (null is never) and how many
                // of them are left.
                'expires_at' => $row->isCredit() && $row->expires_at ? humanDate($row->expires_at, 'j M Y') : null,
                'expires_at_iso' => $row->isCredit() ? isoDate($row->expires_at) : null,
                'remaining' => $row->isCredit() ? (int) $row->remaining : null,
                'at' => humanDate($row->created_at),
                'at_iso' => isoDate($row->created_at),
            ];
        }

        return successReturnPaginated($payload, $rows);
    }

    private function orderCode(PointTransaction $row): ?string
    {
        $source = $row->source;

        return match (true) {
            $source instanceof Order => $source->code,
            $source instanceof OrderTask => $source->order?->code,
            default => null,
        };
    }
}
