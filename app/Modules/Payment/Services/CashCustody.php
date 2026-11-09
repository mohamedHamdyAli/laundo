<?php

namespace App\Modules\Payment\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «الكاش مع المناديب» — cash taken at the door and not yet handed in.
 *
 * Every pound a driver collects is a captured `cash` payment in their name
 * (`TaskService::settlePayment()`), and it stays with them until somebody at
 * the office records receiving it. Until 2026-10-01 cash was a flag on the
 * order and nothing else, so nobody could say how much each driver was holding.
 */
class CashCustody
{
    /**
     * Each driver holding cash, the most first.
     *
     * @return Collection<int, array{driver_id: int, driver: ?Driver, collections: int, amount: float, since: ?Carbon, last_id: int}>
     */
    public function holdings(): Collection
    {
        $rows = Payment::withDrivers()
            ->selectRaw('collected_by, count(*) as collections, sum(amount) as amount, min(captured_at) as since, max(id) as last_id')
            ->groupBy('collected_by')
            ->orderByDesc('amount')
            ->get();

        // A driver whose account was removed still owes what they collected.
        $drivers = Driver::withTrashed()->whereIn('id', $rows->pluck('collected_by'))
            ->get(['id', 'name', 'phone'])->keyBy('id');

        return $rows->map(fn (Payment $row) => [
            'driver_id' => (int) $row->collected_by,
            'driver' => $drivers->get($row->collected_by),
            'collections' => (int) $row->getAttribute('collections'),
            'amount' => round((float) $row->getAttribute('amount'), 2),
            'since' => $row->getAttribute('since') ? Carbon::parse($row->getAttribute('since')) : null,
            // The newest collection on the screen. «استلمت الكاش» posts it back,
            // so cash taken at a door after the page was drawn is not received
            // with the rest.
            'last_id' => (int) $row->getAttribute('last_id'),
        ])->values();
    }

    /**
     * One driver's cash, for their own app — «إجمالي التحصيل الكاش اليومي»
     * (the client's sheet, item 17, 2026-11-08).
     *
     * - `collected_today`: every pound they took at a door today — the
     *   business's day (Cairo), the one the driver works in, not UTC's —
     *   handed in since or not.
     * - `held`: what is still with them, any day, until the office receives it
     *   (`receive()`) — the figure the office will ask them for.
     *
     * @return array{collected_today: float, collections_today: int, held: float}
     */
    public function forDriver(int $driverId): array
    {
        // Both ends on the business's clock, then to UTC: Egypt keeps summer
        // time, and a day is 23 or 25 hours on the two days it changes. The end
        // is tomorrow's own start — a midnight that does not exist (24 April)
        // starts at 01:00, and adding a day to it would keep the 01:00.
        $dayStart = now(displayTimezone())->startOfDay()->utc();
        $dayEnd = now(displayTimezone())->addDay()->startOfDay()->utc();

        $today = Payment::query()
            ->where('method', PaymentMethod::Cash->value)
            ->where('status', PaymentStatus::Captured->value)
            ->where('collected_by', $driverId)
            ->where('captured_at', '>=', $dayStart)
            ->where('captured_at', '<', $dayEnd)
            ->selectRaw('count(*) as collections, coalesce(sum(amount), 0) as amount')
            ->first();

        return [
            'collected_today' => round((float) ($today?->getAttribute('amount') ?? 0), 2),
            'collections_today' => (int) ($today?->getAttribute('collections') ?? 0),
            'held' => round((float) Payment::withDrivers()->where('collected_by', $driverId)->sum('amount'), 2),
        ];
    }

    /**
     * The office has the driver's cash: what the screen showed is in.
     *
     * Up to `$upTo`, the newest collection the operator was looking at: the
     * driver may take more at a door while the page is open, and that is not in
     * the operator's hand. Through the models one by one, so each is in the
     * activity log, and under a lock, so two people pressing at once count it
     * once. The platform's job: a laundry is never handed a driver's cash,
     * whatever it was granted.
     *
     * @return array{count: int, amount: float}
     */
    public function receive(int $driverId, User $receiver, int $upTo): array
    {
        if (LaundryContext::currentId() !== null || $receiver->laundry_id !== null) {
            throw new NotForALaundry;
        }

        return DB::transaction(function () use ($driverId, $receiver, $upTo) {
            $payments = Payment::withDrivers()->where('collected_by', $driverId)
                ->where('id', '<=', $upTo)->lockForUpdate()->get();

            foreach ($payments as $payment) {
                $payment->update(['handed_over_at' => now(), 'received_by' => $receiver->id]);
            }

            return ['count' => $payments->count(), 'amount' => round((float) $payments->sum('amount'), 2)];
        });
    }
}
