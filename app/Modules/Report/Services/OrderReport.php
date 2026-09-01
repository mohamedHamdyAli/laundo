<?php

namespace App\Modules\Report\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Report\Data\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * How much work came through, and what happened to it.
 *
 * The figure worth the report on its own is `priceMovement()`: **how often the
 * laundry's count changes the price, and by how much**. Nobody will ask for it,
 * and it answers a question the business will eventually have — do customers
 * under-count by habit? A systematic gap is a pricing problem, or a wording
 * problem in the app, long before it is an argument with a customer.
 */
class OrderReport
{
    /**
     * @return array<string, int|float>
     */
    public function summary(DateRange $range): array
    {
        $total = $this->inRange($range)->count();
        $cancelled = $this->inRange($range)->where('status', OrderStatus::Cancelled->value)->count();
        $completed = $this->inRange($range)->where('status', OrderStatus::Completed->value)->count();
        $unassigned = $this->inRange($range)->whereNull('laundry_id')->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'unassigned' => $unassigned,
            // Rates rather than raw counts, because a count means nothing without
            // the volume it came out of.
            'cancellation_rate' => $total > 0 ? round($cancelled / $total * 100, 1) : 0.0,
            'unassigned_rate' => $total > 0 ? round($unassigned / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array<int, array{date: string, orders: int}>
     */
    public function daily(DateRange $range): array
    {
        $counts = $this->inRange($range)
            ->selectRaw('date(created_at) d, count(*) c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];

        foreach ($range->eachDay() as $day) {
            // A day with no orders has to appear as a zero or the chart draws
            // straight over it.
            $series[] = ['date' => $day, 'orders' => (int) ($counts[$day] ?? 0)];
        }

        return $series;
    }

    /**
     * @return array<int, array{status: string, label: string, orders: int}>
     */
    public function byStatus(DateRange $range): array
    {
        $counts = $this->inRange($range)
            ->selectRaw('status, count(*) c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = [];

        foreach (OrderStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $out[] = [
                'status' => $status->value,
                'label' => __($status->label()),
                'orders' => $count,
            ];
        }

        return $out;
    }

    /**
     * What the piece review does to the bill.
     *
     * Only orders that were actually reviewed count: an order with no final price
     * has no movement, and folding it in as a zero would drag the average toward
     * "nothing changes" and hide the effect entirely.
     *
     * @return array<string, int|float>
     */
    public function priceMovement(DateRange $range): array
    {
        $reviewed = $this->inRange($range)->whereNotNull('final_total');

        $count = (clone $reviewed)->count();

        if ($count === 0) {
            return [
                'reviewed' => 0, 'increased' => 0, 'decreased' => 0, 'unchanged' => 0,
                'average_change' => 0.0, 'total_change' => 0.0, 'increase_rate' => 0.0,
            ];
        }

        $increased = (clone $reviewed)->whereRaw('final_total > estimated_total')->count();
        $decreased = (clone $reviewed)->whereRaw('final_total < estimated_total')->count();

        $totalChange = (float) (clone $reviewed)->sum(DB::raw('final_total - estimated_total'));

        return [
            'reviewed' => $count,
            'increased' => $increased,
            'decreased' => $decreased,
            'unchanged' => $count - $increased - $decreased,
            'average_change' => round($totalChange / $count, 2),
            'total_change' => round($totalChange, 2),
            // The headline: how often counting finds more than the customer said.
            'increase_rate' => round($increased / $count * 100, 1),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function byZone(DateRange $range): array
    {
        $rows = $this->inRange($range)
            ->join('addresses', 'orders.pickup_address_id', '=', 'addresses.id')
            ->join('zones', 'addresses.zone_id', '=', 'zones.id')
            ->selectRaw('zones.id zid, zones.name zname, count(*) c')
            ->groupBy('zid', 'zname')
            ->orderByDesc('c')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $name = json_decode((string) $row->getAttribute('zname'));

            $out[] = [
                'zone' => $name->{getDefaultLanguage('code')} ?? ($name->ar ?? '—'),
                'orders' => (int) $row->getAttribute('c'),
            ];
        }

        return $out;
    }

    private function inRange(DateRange $range)
    {
        return Order::query()->whereBetween('orders.created_at', [$range->from, $range->to]);
    }
}
