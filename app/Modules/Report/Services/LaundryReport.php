<?php

namespace App\Modules\Report\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderStatusLog;
use App\Modules\Report\Data\DateRange;

/**
 * How well each laundry actually works.
 *
 * Turnaround comes from `order_status_logs` rather than from a column, because
 * nothing ever stored it — the log has recorded who moved every order and when
 * since P6, and this is the first thing to add it up. Measured from `picked_up`
 * to `ready_for_delivery`: the span the laundry genuinely controls, excluding the
 * driving at either end and the customer's own thinking time.
 */
class LaundryReport
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function performance(DateRange $range): array
    {
        $orders = Order::with('laundry:id,name')
            ->whereNotNull('laundry_id')
            ->whereBetween('created_at', [$range->from, $range->to])
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $turnarounds = $this->turnaroundMinutes($orders->pluck('id')->all());
        // What the customer thought. Every figure above this line measures speed
        // or friction; none of them says whether the laundry did a good job.
        $satisfaction = $this->satisfaction($orders->pluck('id')->all());

        $grouped = [];

        foreach ($orders as $order) {
            $key = $order->laundry_id;

            $grouped[$key] ??= [
                'laundry' => $order->laundry
                    ? getLocalizedValueDashboard($order->laundry, 'name')
                    : '—',
                'orders' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'review_rounds' => 0,
                'disputed' => 0,
                'turnarounds' => [],
                'revenue' => 0.0,
            ];

            $grouped[$key]['orders']++;

            if ($order->status === OrderStatus::Completed) {
                $grouped[$key]['completed']++;
            }

            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true)) {
                $grouped[$key]['cancelled']++;
            }

            $grouped[$key]['review_rounds'] += $order->review_round;

            // More than one round means the customer questioned the count.
            if ($order->review_round > 1) {
                $grouped[$key]['disputed']++;
            }

            if ($order->payment_status === 'paid') {
                $grouped[$key]['revenue'] += (float) ($order->final_total ?? $order->estimated_total);
            }

            if (isset($turnarounds[$order->id])) {
                $grouped[$key]['turnarounds'][] = $turnarounds[$order->id];
            }
        }

        $out = [];

        // `$key` is bound here on purpose. Reading it without binding picked up
        // the leaked value from the grouping loop above — the last order's laundry
        // id — so every laundry but one reported another's rating, or none.
        foreach ($grouped as $key => $row) {
            $times = $row['turnarounds'];

            $out[] = [
                'laundry' => $row['laundry'],
                'orders' => $row['orders'],
                'completed' => $row['completed'],
                'cancelled' => $row['cancelled'],
                'disputed' => $row['disputed'],
                'revenue' => round($row['revenue'], 2),
                // No zero guard: a group only exists because an order created
                // it, so the divisor is at least one by construction.
                'average_review_rounds' => round($row['review_rounds'] / $row['orders'], 2),
                // Null rather than zero when nothing finished: an unmeasured
                // turnaround is not an instant one.
                'average_turnaround_hours' => $times === []
                    ? null
                    : round(array_sum($times) / count($times) / 60, 1),
                'measured_orders' => count($times),
                // Null, not zero: unrated and badly rated are different, and a
                // laundry nobody has rated yet must not read as the worst one.
                'average_rating' => $satisfaction[$key]['average'] ?? null,
                'ratings' => $satisfaction[$key]['count'] ?? 0,
                'unhappy' => $satisfaction[$key]['unhappy'] ?? 0,
            ];
        }

        usort($out, fn ($a, $b) => $b['orders'] <=> $a['orders']);

        return $out;
    }

    /**
     * Minutes from collection to ready, per order, read out of the status log.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, int>
     */
    private function turnaroundMinutes(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $logs = OrderStatusLog::whereIn('order_id', $orderIds)
            ->whereIn('to_status', [
                OrderStatus::PickedUp->value,
                OrderStatus::ReadyForDelivery->value,
            ])
            ->orderBy('id')
            ->get(['order_id', 'to_status', 'created_at']);

        $starts = [];
        $out = [];

        foreach ($logs as $log) {
            if ($log->to_status === OrderStatus::PickedUp->value) {
                // The first pickup, not a later one: a re-review does not restart
                // the clock on work already done.
                $starts[$log->order_id] ??= $log->created_at;

                continue;
            }

            if (isset($starts[$log->order_id]) && ! isset($out[$log->order_id])) {
                $out[$log->order_id] = (int) $starts[$log->order_id]->diffInMinutes($log->created_at);
            }
        }

        return $out;
    }

    /**
     * Ratings per laundry, over the orders in this window.
     *
     * Keyed by laundry id and computed from the orders already loaded, rather
     * than by date on the rating itself: a customer may rate days after
     * delivery, and a report about January's work should count January's verdicts
     * wherever they arrived.
     *
     * `withoutGlobalScopes` because the caller has already narrowed to the
     * orders it is allowed to see — applying the tenant scope a second time here
     * would return nothing for a super admin viewing a single laundry.
     *
     * @param  array<int, int>  $orderIds
     * @return array<int, array{average: float, count: int, unhappy: int}>
     */
    private function satisfaction(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $out = [];

        $rows = OrderRating::withoutGlobalScopes()
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('laundry_id')
            ->selectRaw('laundry_id, count(*) as total, avg(overall) as avg_overall')
            ->selectRaw('sum(case when overall <= ? then 1 else 0 end) as unhappy', [OrderRating::POOR_AT_OR_BELOW])
            ->groupBy('laundry_id')
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row->getAttribute('laundry_id')] = [
                // One decimal place. Two on a five-point scale is a precision the
                // data does not have.
                'average' => round((float) $row->getAttribute('avg_overall'), 1),
                'count' => (int) $row->getAttribute('total'),
                'unhappy' => (int) $row->getAttribute('unhappy'),
            ];
        }

        return $out;
    }
}
