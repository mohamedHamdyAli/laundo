<?php

namespace App\Modules\Report\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Payment\Models\Refund;
use App\Modules\Report\Data\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * What came in.
 *
 * **Revenue is paid orders**, cash and card alike, dated by `paid_at`. That is the
 * decision, and it is the honest one: a driver does not mark an order paid until
 * the whole amount is in hand, so `payment_status = paid` means money arrived.
 * Summing `payments` instead would omit every cash order, and cash is the larger
 * share here.
 *
 * **Receivables are counted apart and never inside revenue.** An order delivered
 * and not paid for is not income; it is a number somebody has to chase, and it
 * vanishes the moment it is folded into a total.
 *
 * **Refunds are dated by when they were paid out**, not by the order they came
 * from, so a closed month does not change retroactively.
 *
 * Every query goes through the tenant-scoped `Order`, so a laundry owner's figures
 * are their own without a single `where laundry_id` in this class.
 */
class RevenueReport
{
    /**
     * The headline figures.
     *
     * @return array<string, float|int>
     */
    public function summary(DateRange $range): array
    {
        $paid = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->selectRaw('count(*) orders')
            ->selectRaw('coalesce(sum(coalesce(final_total, estimated_total)), 0) gross')
            ->selectRaw('coalesce(sum(delivery_fee), 0) delivery')
            ->selectRaw('coalesce(sum(discount_total), 0) discounts')
            ->first();

        $refunded = (float) Refund::where('status', Refund::SETTLED)
            ->whereNotNull('settled_at')
            ->whereBetween('settled_at', [$range->from, $range->to])
            ->whereIn('order_id', Order::query()->select('id'))
            ->sum('amount');

        $gross = (float) ($paid->gross ?? 0);

        return [
            'orders' => (int) ($paid->orders ?? 0),
            'gross' => round($gross, 2),
            'delivery_fees' => round((float) ($paid->delivery ?? 0), 2),
            'discounts' => round((float) ($paid->discounts ?? 0), 2),
            'refunds' => round($refunded, 2),
            'net' => round($gross - $refunded, 2),
            // Deliberately outside net: this is owed, not earned.
            'receivables' => $this->receivables($range),
            'average_order' => ($paid->orders ?? 0) > 0 ? round($gross / $paid->orders, 2) : 0.0,
        ];
    }

    /**
     * Delivered, and never paid for.
     *
     * Not dated by `paid_at` — there is none — so it is dated by when the order
     * was placed, which is the only date it has.
     */
    public function receivables(DateRange $range): float
    {
        return round((float) Order::where('payment_status', '!=', 'paid')
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->whereBetween('created_at', [$range->from, $range->to])
            ->sum(DB::raw('coalesce(final_total, estimated_total)')), 2);
    }

    /**
     * Revenue per day, with the empty days present as zeroes.
     *
     * A chart that skips a quiet Tuesday draws a straight line over it and hides
     * exactly the thing somebody opened the report to see.
     *
     * @return array<int, array{date: string, total: float, orders: int}>
     */
    public function daily(DateRange $range): array
    {
        $rows = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->selectRaw('date(paid_at) d')
            ->selectRaw('coalesce(sum(coalesce(final_total, estimated_total)), 0) total')
            ->selectRaw('count(*) orders')
            ->groupBy('d')
            ->pluck('total', 'd');

        $counts = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->selectRaw('date(paid_at) d, count(*) c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];

        foreach ($range->eachDay() as $day) {
            $series[] = [
                'date' => $day,
                'total' => round((float) ($rows[$day] ?? 0), 2),
                'orders' => (int) ($counts[$day] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Which laundries earned it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byLaundry(DateRange $range): array
    {
        $rows = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->with('laundry:id,name')
            ->selectRaw('laundry_id')
            ->selectRaw('count(*) orders')
            ->selectRaw('coalesce(sum(coalesce(final_total, estimated_total)), 0) total')
            ->groupBy('laundry_id')
            ->orderByDesc('total')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'laundry' => $row->laundry
                    ? getLocalizedValueDashboard($row->laundry, 'name')
                    : __('Unassigned'),
                // Aliases from the select, not attributes of an order.
                'orders' => (int) $row->getAttribute('orders'),
                'total' => round((float) $row->getAttribute('total'), 2),
            ];
        }

        return $out;
    }

    /**
     * Which services earned it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byService(DateRange $range): array
    {
        $rows = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->with('service:id,name')
            ->selectRaw('service_id')
            ->selectRaw('count(*) orders')
            ->selectRaw('coalesce(sum(coalesce(final_total, estimated_total)), 0) total')
            ->groupBy('service_id')
            ->orderByDesc('total')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'service' => $row->service
                    ? getLocalizedValueDashboard($row->service, 'name')
                    : '—',
                'orders' => (int) $row->getAttribute('orders'),
                'total' => round((float) $row->getAttribute('total'), 2),
            ];
        }

        return $out;
    }

    /**
     * Cash against card, because the two arrive by different routes and the
     * business reconciles them separately.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byMethod(DateRange $range): array
    {
        $rows = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->selectRaw('payment_method')
            ->selectRaw('count(*) orders')
            ->selectRaw('coalesce(sum(coalesce(final_total, estimated_total)), 0) total')
            ->groupBy('payment_method')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'method' => $row->payment_method ?? '—',
                'orders' => (int) $row->getAttribute('orders'),
                'total' => round((float) $row->getAttribute('total'), 2),
            ];
        }

        return $out;
    }
}
