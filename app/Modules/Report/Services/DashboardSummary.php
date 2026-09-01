<?php

namespace App\Modules\Report\Services;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Report\Data\DateRange;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The home page.
 *
 * It replaced a wall of catalogue counts — total customers, total banners, total
 * countries — none of which anybody could act on. "Total Banners: 0" is not a
 * figure, it is a row count.
 *
 * The rule this service is built on: **every number here is either something
 * happening right now or something waiting for a person.** A count that does not
 * change during a working day, or that nobody would do anything about, belongs on
 * the reports screen and not here.
 *
 * Definitions come from the report services wherever one already exists, so the
 * home page and the reports cannot disagree about what revenue means. Where a
 * figure is only meaningful on a home page — "out with drivers right now" — it is
 * defined here and nowhere else.
 *
 * Everything respects `BelongsToLaundry` through the models, so a laundry owner
 * calling the same methods sees only their own work. The two roles differ in
 * *which* methods they are shown, not in whether the numbers are filtered.
 */
class DashboardSummary
{
    public function __construct(
        private readonly OperationsReport $operations,
        private readonly RevenueReport $revenue,
    ) {}

    /**
     * What has happened since midnight.
     *
     * Deliberately today and not "last 24 hours": operations works in days, and a
     * rolling window makes two people looking at the same screen disagree.
     *
     * @return array<string, mixed>
     */
    public function today(): array
    {
        $from = now()->startOfDay();
        $to = now()->endOfDay();

        return [
            'orders_placed' => Order::whereBetween('created_at', [$from, $to])->count(),

            // Dated by `paid_at`, the same rule the revenue report uses — so the
            // home page and the report never show different money for the same day.
            'money_taken' => (float) Order::whereNotNull('paid_at')
                ->whereBetween('paid_at', [$from, $to])
                ->sum(DB::raw('coalesce(final_total, estimated_total)')),

            'delivered' => Order::where('status', OrderStatus::Delivered->value)
                ->whereBetween('updated_at', [$from, $to])
                ->count(),

            'cancelled' => Order::where('status', OrderStatus::Cancelled->value)
                ->whereBetween('updated_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * Where every live order physically is, right now.
     *
     * The figure a person actually wants on opening the dashboard: not how many
     * orders exist, but how many are sitting in each place — with a driver, at a
     * laundry, waiting to go out.
     *
     * @return array<string, int>
     */
    public function inFlight(): array
    {
        $counts = Order::selectRaw('status, count(*) as total')
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value, OrderStatus::Returned->value])
            ->groupBy('status')
            ->pluck('total', 'status');

        $sum = fn (array $statuses) => collect($statuses)
            ->sum(fn (OrderStatus $s) => (int) ($counts[$s->value] ?? 0));

        return [
            // Booked and nobody has moved yet.
            'awaiting_pickup' => $sum([OrderStatus::AwaitingPickup]),
            // A driver is physically carrying these.
            'with_driver' => $sum([OrderStatus::DriverOnWay, OrderStatus::PickedUp]),
            // At a laundry: being counted, priced, or washed.
            'at_laundry' => $sum([
                OrderStatus::Reviewed,
                OrderStatus::ReviewDisputed,
                OrderStatus::Confirmed,
                OrderStatus::Cleaning,
            ]),
            // Washed and waiting for a delivery leg.
            'ready_to_go' => $sum([OrderStatus::ReadyForDelivery]),
            // Handed over, money still outstanding.
            'delivered_unpaid' => Order::where('status', OrderStatus::Delivered->value)
                ->whereNull('paid_at')
                ->count(),
        ];
    }

    /**
     * The queue. Everything here is waiting for a human decision.
     *
     * Each entry carries a route, because a number somebody cannot click is a
     * number they have to go and find — and a dashboard that makes people search
     * is a dashboard they stop opening.
     *
     * @return array<int, array{key: string, label: string, count: int, route: string|null, severity: string, hint: string}>
     */
    public function needsAPerson(): array
    {
        $snapshot = $this->operations->snapshot();

        $items = [
            [
                'key' => 'unassigned',
                'label' => 'Orders with no laundry',
                'count' => count($snapshot['orders_unassigned']),
                'route' => 'admin.order.index',
                // Nothing can happen to these at all. Worst kind of waiting.
                'severity' => 'critical',
                'hint' => 'No laundry covers the address, or none was chosen',
            ],
            [
                'key' => 'tasks_queued',
                'label' => 'Journeys with no driver',
                'count' => count($snapshot['tasks_queued']),
                'route' => 'admin.report.operations',
                'severity' => 'critical',
                'hint' => 'Dispatch found nobody eligible',
            ],
            [
                'key' => 'awaiting_customer',
                'label' => 'Waiting on a customer to confirm a price',
                'count' => count($snapshot['orders_awaiting_customer']),
                'route' => 'admin.order.index',
                // Nothing times these out, by decision. They wait forever unless
                // somebody calls, which is the reason they are on the home page.
                'severity' => 'warning',
                'hint' => 'Nothing times these out — somebody has to call',
            ],
            [
                'key' => 'complaints',
                'label' => 'Open complaints',
                'count' => Complaint::open()->count(),
                'route' => 'admin.complaint.index',
                'severity' => Complaint::open()->where('created_at', '<', now()->subDay())->exists()
                    ? 'critical'
                    : 'warning',
                'hint' => 'Answered by phone, so nothing closes itself',
            ],
            [
                'key' => 'price_questions',
                'label' => 'Unanswered price questions',
                'count' => count($snapshot['price_questions_open']),
                'route' => 'admin.order.index',
                'severity' => 'warning',
                'hint' => 'A customer asked something and is waiting',
            ],
            [
                'key' => 'refunds',
                'label' => 'Refunds to decide',
                'count' => (int) $snapshot['refunds_pending'],
                'route' => 'admin.refund.index',
                'severity' => 'warning',
                'hint' => 'Requested and not yet approved or rejected',
            ],
            [
                'key' => 'refunds_unsettled',
                'label' => 'Refunds approved but unpaid',
                'count' => (int) $snapshot['refunds_unsettled'],
                'route' => 'admin.refund.index',
                // Approved is a promise. Unpaid is a broken one.
                'severity' => 'critical',
                'hint' => 'The payout never completed',
            ],
            [
                'key' => 'tasks_exhausted',
                'label' => 'Journeys that ran out of attempts',
                'count' => count($snapshot['tasks_exhausted']),
                'route' => 'admin.report.operations',
                'severity' => 'critical',
                'hint' => 'Escalated — a person has to intervene',
            ],
            [
                'key' => 'wallets',
                'label' => 'Wallets that disagree with their ledger',
                'count' => count($snapshot['wallets_out_of_balance']),
                'route' => 'admin.wallet.index',
                'severity' => 'critical',
                'hint' => 'Nothing else in the system reports this',
            ],
        ];

        // Only what is actually waiting. A row of zeroes trains people to stop
        // reading the list, and then the one that is not zero goes unnoticed.
        return array_values(array_filter($items, fn ($item) => $item['count'] > 0));
    }

    /**
     * The month so far, using the reports' own definitions.
     *
     * @return array<string, mixed>
     */
    public function thisMonth(): array
    {
        $range = new DateRange(now()->startOfMonth(), now()->endOfDay());

        $summary = $this->revenue->summary($range);

        $placed = Order::whereBetween('created_at', [$range->from, $range->to])->count();
        $cancelled = Order::where('status', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->count();

        $ratings = OrderRating::whereBetween('created_at', [$range->from, $range->to]);

        return [
            'net_revenue' => $summary['net'],
            'receivables' => $summary['receivables'],
            'paid_orders' => $summary['orders'],
            'placed' => $placed,
            // A rate, not a count: five cancellations out of six is a crisis and
            // five out of five hundred is a Tuesday.
            'cancellation_rate' => $placed > 0 ? round($cancelled / $placed * 100, 1) : 0.0,
            // Null, not zero. Nobody having rated is not the same as a bad score.
            'average_rating' => $ratings->clone()->count() > 0
                ? round((float) $ratings->clone()->avg('overall'), 1)
                : null,
            'unhappy' => $ratings->clone()->poor()->count(),
        ];
    }

    /**
     * Drivers, right now.
     *
     * Not tenant-filtered, because tasks are not — so this belongs only on the
     * super admin's page, and the caller is responsible for not showing it to a
     * laundry.
     *
     * @return array<string, int>
     */
    public function drivers(): array
    {
        $total = User::whereHas('role', fn ($q) => $q->where('slug', 'driver'))->count();

        $busy = OrderTask::open()->whereNotNull('driver_id')->distinct('driver_id')->count('driver_id');

        return [
            'total' => $total,
            'busy' => $busy,
            // Available is what is left, floored at zero: a driver can hold more
            // than one order, so busy can never exceed total but the subtraction
            // should not be able to go negative if it ever did.
            'idle' => max($total - $busy, 0),
            'open_journeys' => OrderTask::open()->count(),
        ];
    }

    /**
     * What a laundry has to do next, in the order it has to do it.
     *
     * These four are the laundry's actual working day. Everything else on their
     * page is context; this is the list.
     *
     * @return array<int, array{key: string, label: string, count: int, hint: string, severity: string}>
     */
    public function laundryQueue(): array
    {
        $items = [
            [
                'key' => 'to_count',
                'label' => 'Waiting to be counted',
                'count' => Order::whereIn('status', [
                    OrderStatus::PickedUp->value,
                    OrderStatus::ReviewDisputed->value,
                ])->count(),
                'severity' => 'critical',
                // Until the pieces are counted the customer has no final price and
                // the order cannot move at all.
                'hint' => 'Nothing else can happen until the pieces are priced',
            ],
            [
                'key' => 'awaiting_customer',
                'label' => 'Priced, waiting for the customer',
                'count' => Order::where('status', OrderStatus::Reviewed->value)->count(),
                'severity' => 'warning',
                'hint' => 'Not your move — but it is your machine time being held',
            ],
            [
                'key' => 'to_start',
                'label' => 'Confirmed and not started',
                'count' => Order::where('status', OrderStatus::Confirmed->value)->count(),
                'severity' => 'critical',
                'hint' => 'The customer agreed to the price. The clock is running',
            ],
            [
                'key' => 'to_finish',
                'label' => 'In cleaning',
                'count' => Order::where('status', OrderStatus::Cleaning->value)->count(),
                'severity' => 'info',
                'hint' => 'Mark ready when done and a driver is dispatched',
            ],
            [
                'key' => 'ready',
                'label' => 'Ready, waiting for a driver',
                'count' => Order::where('status', OrderStatus::ReadyForDelivery->value)->count(),
                'severity' => 'info',
                'hint' => 'Done on your side',
            ],
        ];

        return array_values(array_filter($items, fn ($item) => $item['count'] > 0));
    }

    /**
     * A laundry's own scorecard for the month.
     *
     * @return array<string, mixed>
     */
    public function laundryScore(): array
    {
        $range = new DateRange(now()->startOfMonth(), now()->endOfDay());

        $orders = Order::whereBetween('created_at', [$range->from, $range->to]);

        $ratings = OrderRating::whereBetween('created_at', [$range->from, $range->to]);
        $rated = $ratings->clone()->count();

        return [
            'orders' => $orders->clone()->count(),
            'completed' => $orders->clone()->where('status', OrderStatus::Completed->value)->count(),
            // More than one review round means the customer questioned the count.
            'disputed' => $orders->clone()->where('review_round', '>', 1)->count(),
            'revenue' => (float) $orders->clone()
                ->whereNotNull('paid_at')
                ->sum(DB::raw('coalesce(final_total, estimated_total)')),
            'average_rating' => $rated > 0 ? round((float) $ratings->clone()->avg('overall'), 1) : null,
            'ratings' => $rated,
            'unhappy' => $ratings->clone()->poor()->count(),
        ];
    }

    /**
     * The orders somebody should look at first — oldest problem first.
     *
     * @return Collection<int, Order>
     */
    public function attentionOrders(int $limit = 8)
    {
        return Order::with(['customer:id,name,phone', 'laundry:id,name'])
            ->where(function ($query) {
                $query
                    // Nobody can act on these at all.
                    ->whereNull('laundry_id')
                    // Or waiting on a customer who may never answer.
                    ->orWhere('status', OrderStatus::Reviewed->value)
                    // Or the count was questioned.
                    ->orWhere('status', OrderStatus::ReviewDisputed->value);
            })
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            // Oldest first. A dashboard sorted newest-first hides the order that
            // has been stuck since Tuesday behind the one placed a minute ago.
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * True when the viewer is confined to one laundry.
     *
     * The page uses it to choose which half to render — not to decide whether the
     * numbers are filtered, which the models already handle.
     */
    public function isLaundryView(): bool
    {
        return LaundryContext::isTenant();
    }
}
