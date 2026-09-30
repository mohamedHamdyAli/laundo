<?php

namespace App\Modules\Report\Services;

use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Report\Data\DateRange;
use App\Support\LaundryContext;

/**
 * «ملخص الماليات» — the money that used to be on the home page.
 *
 * Moved, not rewritten: every figure comes from `RevenueReport`, so this page,
 * the revenue report and the home page before it all mean the same thing by
 * «money taken» and «net revenue» — dated by `paid_at`, refunds out of net,
 * owed kept outside it.
 *
 * Scoped by the models like everything else (`Order` carries
 * `BelongsToLaundry`), so a laundry account somebody grants `finance.view`
 * reads its own orders' money and no other laundry's.
 */
class FinanceSummary
{
    public function __construct(private readonly RevenueReport $revenue) {}

    /**
     * Since midnight.
     *
     * @return array{money_taken: float, paid_orders: int}
     */
    public function today(): array
    {
        $summary = $this->revenue->summary(new DateRange(now()->startOfDay(), now()->endOfDay()));

        return [
            'money_taken' => $summary['gross'],
            'paid_orders' => $summary['orders'],
        ];
    }

    /**
     * The month so far.
     *
     * @return array{net_revenue: float, receivables: float, paid_orders: int, refunds: float}
     */
    public function thisMonth(): array
    {
        $summary = $this->revenue->summary(new DateRange(now()->startOfMonth(), now()->endOfDay()));

        return [
            'net_revenue' => $summary['net'],
            // Owed, not earned — never inside net.
            'receivables' => $summary['receivables'],
            'paid_orders' => $summary['orders'],
            'refunds' => $summary['refunds'],
        ];
    }

    /**
     * The month so far against the same days of last month — the 1st to the
     * 30th of September against the 1st to the 30th of August, never a whole
     * month against part of one. Null change when last month had nothing to
     * compare with: «up from zero» is no percentage.
     *
     * @return array<string, array{now: float|int, before: float|int, change: float|null}>
     */
    public function compared(): array
    {
        $now = $this->revenue->summary(new DateRange(now()->startOfMonth(), now()->endOfDay()));
        $before = $this->revenue->summary(new DateRange(
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfDay(),
        ));

        $pair = fn (string $key) => [
            'now' => $now[$key],
            'before' => $before[$key],
            'change' => $before[$key] > 0 ? round(($now[$key] - $before[$key]) / $before[$key] * 100, 1) : null,
        ];

        return [
            'gross' => $pair('gross'),
            'net' => $pair('net'),
            'orders' => $pair('orders'),
            'average_order' => $pair('average_order'),
        ];
    }

    /**
     * What this month's settled orders paid, and to whom.
     *
     * The washing is the only money the platform and a laundry divide
     * (`cleaningRevenue()`): the laundry's part and the platform's part of it
     * are the two halves of the bar. The customer's platform fee, the
     * delivery fees and the tax are beside it, not in it — dividing them is
     * the bug `SettlementService::basisFor()` exists to prevent.
     *
     * @return array{orders: int, laundries: float, platform: float, platform_fees: float, tax: float, delivery_fees: float, discounts: float}
     */
    public function split(): array
    {
        $month = [now()->startOfMonth(), now()->endOfDay()];

        $settled = OrderSettlement::where('status', OrderSettlement::SETTLED)
            ->whereBetween('settled_at', $month)
            ->selectRaw('count(*) n')
            ->selectRaw('coalesce(sum(laundry_amount), 0) laundries')
            ->selectRaw('coalesce(sum(commission_amount), 0) platform')
            ->selectRaw('coalesce(sum(platform_fee_amount), 0) fees')
            ->selectRaw('coalesce(sum(tax_amount), 0) tax')
            ->first();

        $summary = $this->revenue->summary(new DateRange($month[0], $month[1]));

        return [
            'orders' => (int) $settled->getAttribute('n'),
            'laundries' => round((float) $settled->getAttribute('laundries'), 2),
            // Can be negative: a coupon the platform pays for costs it more than
            // its part of the washing. Shown as it is, not floored.
            'platform' => round((float) $settled->getAttribute('platform'), 2),
            'platform_fees' => round((float) $settled->getAttribute('fees'), 2),
            'tax' => round((float) $settled->getAttribute('tax'), 2),
            'delivery_fees' => $summary['delivery_fees'],
            'discounts' => $summary['discounts'],
        ];
    }

    /**
     * Money not yet where it is going.
     *
     * The drivers' figure is not tenant-scoped (driver earnings carry no
     * laundry_id), so it is null for a laundry account and the page leaves it
     * out — the same rule as the home page's drivers card.
     *
     * @return array{receivables: float, settlements: array{count: int, amount: float}, drivers: float|null}
     */
    public function owed(): array
    {
        $pending = OrderSettlement::where('status', OrderSettlement::PENDING)
            ->selectRaw('count(*) n, coalesce(sum(laundry_amount), 0) amount')
            ->first();

        return [
            'receivables' => $this->revenue->receivables(new DateRange(now()->startOfMonth(), now()->endOfDay())),
            'settlements' => [
                'count' => (int) $pending->getAttribute('n'),
                'amount' => round((float) $pending->getAttribute('amount'), 2),
            ],
            'drivers' => LaundryContext::isTenant()
                ? null
                : round((float) DriverEarning::where('status', DriverEarning::PENDING)->sum('amount'), 2),
        ];
    }

    /**
     * This month's money by payment method, each method under its own label.
     *
     * @return array<int, array{label: string, orders: int, total: float, slot: int}>
     */
    public function byMethod(): array
    {
        // The slot order: cash first — the one this install runs on, so it
        // takes the first colour — then the rest in the enum's order.
        $cases = [
            PaymentMethod::Cash,
            ...array_values(array_filter(PaymentMethod::cases(), fn (PaymentMethod $case) => $case !== PaymentMethod::Cash)),
        ];

        return collect($this->revenue->byMethod(new DateRange(now()->startOfMonth(), now()->endOfDay())))
            ->map(function (array $row) use ($cases) {
                $method = $row['method'] instanceof PaymentMethod ? $row['method'] : PaymentMethod::tryFrom((string) $row['method']);
                // A method keeps its colour whatever it earned this month.
                $slot = $method ? array_search($method, $cases, true) + 1 : count($cases) + 1;

                return [
                    'label' => $method ? __($method->label()) : (string) $row['method'],
                    'orders' => $row['orders'],
                    'total' => $row['total'],
                    'slot' => $slot,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * This month's money by service or by laundry: the top few, the rest summed.
     *
     * @return array{rows: array<int, array{label: string, orders: int, total: float}>, other: float}
     */
    public function top(string $by, int $shown = 5): array
    {
        $range = new DateRange(now()->startOfMonth(), now()->endOfDay());
        $rows = $by === 'laundry' ? $this->revenue->byLaundry($range) : $this->revenue->byService($range);
        $key = $by === 'laundry' ? 'laundry' : 'service';

        $kept = array_slice($rows, 0, $shown);

        return [
            'rows' => array_map(fn (array $row) => [
                'label' => (string) $row[$key],
                'orders' => $row['orders'],
                'total' => $row['total'],
            ], $kept),
            'other' => round(array_sum(array_column(array_slice($rows, $shown), 'total')), 2),
        ];
    }

    public function isLaundryView(): bool
    {
        return LaundryContext::isTenant();
    }

    /**
     * Money taken per day, quiet days included as zero.
     *
     * @return array<int, array{date: string, total: float, orders: int}>
     */
    public function byDay(int $days = 14): array
    {
        return $this->revenue->daily(
            new DateRange(now()->startOfDay()->subDays($days - 1), now()->endOfDay())
        );
    }
}
