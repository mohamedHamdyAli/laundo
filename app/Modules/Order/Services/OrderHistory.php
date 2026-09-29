<?php

namespace App\Modules\Order\Services;

use App\Models\ActivityLog;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderStatusLog;
use App\Services\ActivityPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Everything that happened to one order, newest first, and who did it.
 *
 * Two sources, merged by time:
 *
 *   - `order_status_logs` — every status the order passed through, with its
 *     actor and note. The customer app's tracking screen is built from these,
 *     and they are never pruned.
 *   - `activity_logs` rows carrying this order's id — every change to the order
 *     itself and to what hangs off it: the pieces counted, the laundry given,
 *     the driver legs, the payment, a refund, the settlement. Worded by
 *     ActivityPresenter, the same way the activity log screen words them.
 *
 * An order's own status is shown by its status entry, not repeated in the
 * change that carried it; a change left with nothing else to say is dropped.
 *
 * **The order's screen is not a way round any other permission.** Anybody who
 * can see the order sees this — a laundry owner included — so only the order's
 * own working parts show to everyone (`OPEN`); a complaint, a driver's pay, a
 * payment, a refund or the settlement shows only to somebody who may open that
 * screen anyway (`GATED`); and anything else only to whoever may read the
 * whole activity log. The home page withholds the money panels the same way.
 *
 * @phpstan-type Entry array{at: \DateTimeInterface|null, kind: string, title: string, note: ?string, actor: ?string, role: ?string, where: ?string, fields: list<array{key: string, label: string, old: string, new: string}>}
 */
class OrderHistory
{
    /**
     * The order's own working parts: shown to anybody who may see the order.
     *
     * An allow-list, not a deny-list. Anything else carrying this order's id —
     * a complaint about it, a driver's pay for it, the money — shows only with
     * its own screen's permission, and a kind of record added later that
     * nobody thought to name here shows only to whoever may read the whole
     * activity log. A laundry never reads a complaint (see Complaint's
     * docblock), and a card on the order's screen must not be the way round it.
     *
     * @var list<string>
     */
    private const OPEN = [
        'Order', 'OrderItem', 'OrderTask', 'OrderMedia', 'OrderPriceQuery', 'CouponRedemption',
        // A disagreement about how many pieces there are is about the order's
        // own pieces — the laundry is told of it and sees it on the order.
        'PieceDiscrepancy',
    ];

    /**
     * Kinds of change that show only with the permission of their own screen.
     *
     * @var array<string, string>
     */
    private const GATED = [
        'DriverEarning' => 'driver_earning.view',
        'Payment' => 'payment.view',
        'Refund' => 'refund.view',
        'OrderSettlement' => 'order_settlement.view',
        'Complaint' => 'complaint.view',
        'ComplaintAttachment' => 'complaint.view',
        'OrderRating' => 'order_rating.view',
        'OrderRecurrence' => 'order_recurrence.view',
    ];

    public function __construct(private readonly ActivityPresenter $presenter) {}

    /**
     * @return list<Entry>
     */
    public function for(Order $order): array
    {
        $order->loadMissing('statusLogs.actor:id,name,role_id', 'statusLogs.actor.role:id,slug');

        $logs = ActivityLog::query()
            ->where('order_id', $order->getKey())
            ->latest('id')
            ->limit(300)
            ->get()
            ->filter(fn (ActivityLog $log) => $this->mayRead($log));

        $entries = [];

        foreach ($order->statusLogs as $log) {
            $entries[] = $this->status($log);
        }

        foreach ($this->presenter->present($logs) as $row) {
            if ($entry = $this->change($row)) {
                $entries[] = $entry;
            }
        }

        usort($entries, fn (array $a, array $b) => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return $entries;
    }

    private function mayRead(ActivityLog $log): bool
    {
        $kind = class_basename((string) $log->subject_type);

        if (in_array($kind, self::OPEN, true)) {
            return true;
        }

        return canDo(self::GATED[$kind] ?? 'activity_log.view');
    }

    /**
     * @return Entry
     */
    private function status(OrderStatusLog $log): array
    {
        $slug = $log->actor?->role?->slug;

        return [
            'at' => $log->created_at,
            'kind' => 'status',
            'title' => (string) __(OrderStatus::tryFrom($log->to_status)?->label() ?? $log->to_status),
            'note' => $log->note,
            'actor' => $log->actor->name ?? ($log->actor_type === 'system' ? __('The system') : null),
            'role' => match (true) {
                $slug !== null => __(config("activity.roles.$slug") ?? Str::headline($slug)),
                $log->actor_type !== '' && $log->actor_type !== 'system' => __(Str::headline($log->actor_type)),
                default => null,
            },
            'where' => null,
            'fields' => [],
        ];
    }

    /**
     * @param  array{log: ActivityLog, at: Carbon|null, event: string, title: string, actor: string, role: string|null, where: string, fields: list<array{key: string, label: string, old: string, new: string}>}  $row
     * @return Entry|null
     */
    private function change(array $row): ?array
    {
        $isOrder = $row['log']->subject_type === (new Order)->getMorphClass();

        // The order's status is its own entry above; repeating it here would
        // show every transition twice.
        $fields = array_values(array_filter(
            $row['fields'],
            fn (array $field) => ! ($isOrder && $field['key'] === 'status')
        ));

        if ($row['event'] === 'updated' && $fields === []) {
            return null;
        }

        return [
            'at' => $row['at'],
            'kind' => $row['event'],
            'title' => $row['title'],
            'note' => null,
            'actor' => $row['actor'],
            'role' => $row['role'],
            'where' => $row['where'],
            'fields' => $fields,
        ];
    }
}
