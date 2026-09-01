<?php

namespace App\Modules\Report\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Report\Data\DateRange;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The week, in the shape an email can carry.
 *
 * Every figure here comes out of the existing report services rather than a query
 * written for the email. That is the whole design constraint: the moment a digest
 * computes its own revenue, it can disagree with the reports screen about the same
 * week, and there is no way to tell which one is wrong from the outside.
 *
 * A laundry's figures are produced by running the same services **as that
 * laundry's owner**, so the tenant scope on `Order` does the filtering. The
 * alternative — a parallel set of `where laundry_id` queries in a mailer — is how
 * the emailed number and the on-screen number drift apart.
 */
class WeeklyDigest
{
    public function __construct(
        private readonly RevenueReport $revenue,
        private readonly OrderReport $orders,
        private readonly OperationsReport $operations,
    ) {}

    /**
     * The week that just ended: the seven days up to and including yesterday.
     *
     * Run on a Sunday morning this is Sunday to Saturday, which is how the
     * business talks about a week — and it never includes today, because a
     * half-finished day in a weekly total is a number that changes after it has
     * been read.
     */
    public static function lastWeek(): DateRange
    {
        $to = now()->subDay()->endOfDay();

        return new DateRange($to->copy()->subDays(6)->startOfDay(), $to);
    }

    /**
     * What the platform saw.
     *
     * @return array<string, mixed>
     */
    public function platform(DateRange $range): array
    {
        $revenue = $this->revenue->summary($range);
        $orders = $this->orders->summary($range);

        return [
            'scope' => 'platform',
            'title' => __('The platform this week'),
            'range' => $range,
            'headline' => [
                __('Orders') => $orders['total'],
                __('Completed') => $orders['completed'],
                __('Cancelled') => $orders['cancelled'].' ('.$orders['cancellation_rate'].'%)',
                __('Net revenue') => moneyFormat($revenue['net']),
                __('Owed to us') => moneyFormat($revenue['receivables']),
                __('Average order') => moneyFormat($revenue['average_order']),
            ],
            // Not a statistic: the things still waiting for a person on the
            // morning the email arrives. A weekly summary of a queue that is
            // already empty would be worse than useless.
            'waiting' => $this->waiting(),
            'rows' => $this->laundryRows($range),
            'rows_title' => __('By laundry'),
            // Gross, not net: refunds are dated by when they were paid out and
            // are not attributable to a laundry's week, so the column would be
            // a different quantity under the same word as the headline.
            'rows_headers' => [__('Laundry'), __('Orders'), __('Gross')],
        ];
    }

    /**
     * What one laundry saw. Scoped by acting as its owner.
     *
     * @return array<string, mixed>
     */
    public function forLaundry(Laundry $laundry, User $owner, DateRange $range): array
    {
        return $this->as($owner, function () use ($laundry, $range): array {
            $revenue = $this->revenue->summary($range);
            $orders = $this->orders->summary($range);

            return [
                'scope' => 'laundry',
                'title' => getLocalizedValueDashboard($laundry, 'name'),
                'range' => $range,
                'headline' => [
                    __('Orders') => $orders['total'],
                    __('Completed') => $orders['completed'],
                    __('Cancelled') => $orders['cancelled'].' ('.$orders['cancellation_rate'].'%)',
                    __('Revenue') => moneyFormat($revenue['net']),
                    __('Average order') => moneyFormat($revenue['average_order']),
                ],
                'waiting' => $this->waitingForLaundry(),
                'rows' => $this->serviceRows($range),
                'rows_title' => __('By service'),
                'rows_headers' => [__('Service'), __('Orders'), __('Gross')],
            ];
        });
    }

    /**
     * Rows for the CSV attachment — the same numbers, machine-readable.
     *
     * @param  array<string, mixed>  $digest
     * @return array<int, array<int, string|int|float>>
     */
    public function csv(array $digest): array
    {
        $out = [[__('Figure'), __('Value')]];

        foreach ($digest['headline'] as $label => $value) {
            $out[] = [$label, (string) $value];
        }

        if ($digest['rows'] !== []) {
            $out[] = [];
            $out[] = $digest['rows_headers'];

            foreach ($digest['rows'] as $row) {
                $out[] = [$row['label'], $row['orders'], $row['revenue']];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ pieces

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function waiting(): array
    {
        $snapshot = $this->operations->snapshot();

        // `snapshot()` returns the rows themselves for most of these, not counts,
        // because the operations screen lists them. Here only the size matters.
        $rows = [
            ['label' => __('Orders with no laundry'), 'count' => count($snapshot['orders_unassigned'])],
            ['label' => __('Journeys with no driver'), 'count' => count($snapshot['tasks_queued'])],
            ['label' => __('Waiting on the customer'), 'count' => count($snapshot['orders_awaiting_customer'])],
            ['label' => __('Journeys out of attempts'), 'count' => count($snapshot['tasks_exhausted'])],
            ['label' => __('Unanswered price questions'), 'count' => count($snapshot['price_questions_open'])],
            ['label' => __('Refunds to decide'), 'count' => (int) $snapshot['refunds_pending']],
            ['label' => __('Wallets out of balance'), 'count' => count($snapshot['wallets_out_of_balance'])],
        ];

        // A zero never appears. A column of noughts teaches people to stop
        // reading the list, and then the one that is not a nought is missed too.
        return array_values(array_filter($rows, fn ($row) => $row['count'] > 0));
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    private function waitingForLaundry(): array
    {
        $rows = [
            ['label' => __('Waiting to be counted'), 'count' => Order::where('status', OrderStatus::PickedUp->value)->count()],
            ['label' => __('Priced, waiting for the customer'), 'count' => Order::where('status', OrderStatus::Reviewed->value)->count()],
            ['label' => __('Confirmed, not started'), 'count' => Order::where('status', OrderStatus::Confirmed->value)->count()],
            ['label' => __('Ready, waiting to go out'), 'count' => Order::where('status', OrderStatus::ReadyForDelivery->value)->count()],
        ];

        return array_values(array_filter($rows, fn ($row) => $row['count'] > 0));
    }

    /**
     * @return array<int, array{label: string, orders: int, revenue: float}>
     */
    private function laundryRows(DateRange $range): array
    {
        return array_map(fn (array $row) => [
            'label' => (string) ($row['laundry'] ?? '—'),
            'orders' => (int) ($row['orders'] ?? 0),
            'revenue' => round((float) ($row['total'] ?? 0), 2),
        ], $this->revenue->byLaundry($range));
    }

    /**
     * @return array<int, array{label: string, orders: int, revenue: float}>
     */
    private function serviceRows(DateRange $range): array
    {
        return array_map(fn (array $row) => [
            'label' => (string) ($row['service'] ?? '—'),
            'orders' => (int) ($row['orders'] ?? 0),
            'revenue' => round((float) ($row['total'] ?? 0), 2),
        ], $this->revenue->byService($range));
    }

    /**
     * Run a closure as somebody, then put the guard back exactly as it was.
     *
     * The console has no authenticated user, which is precisely why every tenant
     * scope lets it see everything. Borrowing the owner's identity for the length
     * of one report is what makes «كل مغسلة تشوف أرقامها هي» true without a second
     * implementation of the scoping rules.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function as(User $owner, callable $work): mixed
    {
        $previous = Auth::user();

        Auth::setUser($owner);

        try {
            return $work();
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }
}
