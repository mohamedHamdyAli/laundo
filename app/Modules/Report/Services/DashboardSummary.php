<?php

namespace App\Modules\Report\Services;

use App\Modules\Complaint\Models\Complaint;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderStatusLog;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Report\Data\DateRange;
use App\Modules\Service\Models\Service;
use App\Modules\User\Models\User;
use App\Support\LaundryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
 *
 * **No money here** (the owner, 2026-09-30). The home page has no permission of
 * its own — every panel account opens it — so a figure in pounds on it was a
 * figure every moderator, driver supervisor and laundry's staff read. The money
 * lives on «ملخص الماليات» (`FinanceSummary`, `finance.view`); this page counts.
 */
class DashboardSummary
{
    public function __construct(
        private readonly OperationsReport $operations,
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

            // Collected from the customer today — the moment the order log says
            // it happened, not the order's last update.
            'picked_up' => $this->reachedStatus(OrderStatus::PickedUp)
                ->whereBetween('created_at', [$from, $to])
                ->distinct()
                ->count('order_id'),

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
    /**
     * The hint for an item counted in journeys but opened as a list of orders.
     *
     * Reported on a live install: the home page said 15 and the screen it opened
     * listed 6 orders, which reads as two numbers disagreeing. It was neither —
     * an order has four legs, and 15 driverless legs belonged to 4 orders.
     *
     * Returned as a key **plus** its parameters rather than an interpolated
     * string, because the view is what calls `__()`. A hint with its numbers
     * already substituted matches no translation key, so it would render in
     * English on an Arabic panel.
     *
     * The clause is added only when the two counts differ — "1 journeys across 1
     * orders" both reads badly and says nothing — and the one-order case has its
     * own sentence rather than an "(s)".
     *
     * @param  array<int, array<string, mixed>>  $legs
     * @return array{hint: string, hintParams?: array<string, int>}
     */
    private function legsHint(string $base, array $legs): array
    {
        $count = count($legs);
        $orders = collect($legs)->pluck('order_id')->unique()->count();

        if ($count === 0 || $count === $orders) {
            return ['hint' => $base];
        }

        if ($orders === 1) {
            return [
                'hint' => $base.' — :legs journeys on one order',
                'hintParams' => ['legs' => $count],
            ];
        }

        return [
            'hint' => $base.' — :legs journeys across :orders orders',
            'hintParams' => ['legs' => $count, 'orders' => $orders],
        ];
    }

    /**
     * Where a driverless-journey row should open.
     *
     * The dispatch board when the reader can see it: it lists the legs
     * themselves and can assign one, where the filtered order list still has
     * them opening an order at a time to reach the same table.
     *
     * The filtered order list stays as the fallback, because a role can hold
     * `order.view` without `order_task.view` and a queue row that opens a 403
     * is worse than one that opens a longer route to the same place.
     *
     * @return array{route: string, params: array<string, string>}
     */
    private function dispatchTarget(string $fallbackFilter): array
    {
        return canDo('order_task.view')
            ? ['route' => 'admin.dispatch.index', 'params' => []]
            : ['route' => 'admin.order.index', 'params' => ['status' => $fallbackFilter]];
    }

    public function needsAPerson(): array
    {
        $snapshot = $this->operations->snapshot();

        $items = [
            [
                'key' => 'unassigned',
                'label' => 'Orders with no laundry',
                'count' => count($snapshot['orders_unassigned']),
                'route' => 'admin.order.index',
                'params' => ['status' => OrderRepository::NEEDS_LAUNDRY],
                // Nothing can happen to these at all. Worst kind of waiting.
                'severity' => 'critical',
                'hint' => 'No laundry covers the address, or none was chosen',
            ],
            [
                'key' => 'tasks_queued',
                'label' => 'Journeys with no driver',
                'count' => count($snapshot['tasks_queued']),
                /*
                 * The orders, not the operations report.
                 *
                 * This pointed at `admin.report.operations`, which counts these
                 * legs and lists their order codes and can do nothing about
                 * them: a driver is assigned on the order's own screen. So the
                 * one queue item whose whole point is "somebody has to act"
                 * sent that somebody to a page with no action on it, and left
                 * them to find four orders by hand in a list of every order.
                 */
                ...$this->dispatchTarget(OrderRepository::NEEDS_DRIVER),
                'severity' => 'critical',
                ...$this->legsHint('Dispatch found nobody eligible', $snapshot['tasks_queued']),
            ],
            [
                'key' => 'piece_mismatch',
                'label' => 'The piece count does not match',
                'count' => $this->openPieceChecks(),
                'route' => 'admin.order.index',
                'params' => ['status' => OrderRepository::PIECE_MISMATCH],
                // Pieces may be missing. Nothing else on this list means that.
                'severity' => 'critical',
                // A driver at a handover, or the laundry at its review.
                'hint' => 'Two counts of the same pieces disagreed',
            ],
            [
                'key' => 'awaiting_customer',
                'label' => 'Waiting on a customer to confirm a price',
                'count' => count($snapshot['orders_awaiting_customer']),
                'route' => 'admin.order.index',
                // This one *is* a status, so no pseudo-filter is needed.
                'params' => ['status' => OrderStatus::Reviewed->value],
                // Nothing times these out, by decision. They wait forever unless
                // somebody calls, which is the reason they are on the home page.
                'severity' => 'warning',
                'hint' => 'Nothing times these out — somebody has to call',
            ],
            [
                'key' => 'laundry_applications',
                'label' => 'Laundries waiting to be approved',
                'count' => Laundry::withoutGlobalScopes()->pending()->count(),
                'route' => 'admin.laundry.pending',
                // Warning, not critical: nobody is stranded and no order is
                // stuck. But an application nobody answers is a supplier who
                // signs with somebody else, so it does not belong off the page.
                'severity' => 'warning',
                'hint' => 'Applied through the public form and cannot sign in yet',
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
                'params' => ['status' => OrderRepository::NEEDS_PRICE_ANSWER],
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
                // The third item that pointed at the operations report, and the
                // same objection: a person intervenes by releasing the leg and
                // handing it to a driver, both of which are on the order.
                ...$this->dispatchTarget(OrderRepository::NEEDS_RESCUE),
                'severity' => 'critical',
                ...$this->legsHint('Escalated — a person has to intervene', $snapshot['tasks_exhausted']),
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

        $placed = Order::whereBetween('created_at', [$range->from, $range->to])->count();
        $cancelled = Order::where('status', OrderStatus::Cancelled->value)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->count();

        $ratings = OrderRating::whereBetween('created_at', [$range->from, $range->to]);

        return [
            'placed' => $placed,
            'completed' => Order::where('status', OrderStatus::Completed->value)
                ->whereBetween('created_at', [$range->from, $range->to])
                ->count(),
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
     * @return array<int, array{key: string, label: string, count: int, hint: string, severity: string, route?: string, params?: array<string, string>}>
     */
    public function laundryQueue(): array
    {
        $items = [
            [
                // Theirs to know about, the platform's to close.
                'key' => 'piece_mismatch',
                'label' => 'The piece count does not match',
                'count' => $this->openPieceChecks(),
                'route' => 'admin.order.index',
                'params' => ['status' => OrderRepository::PIECE_MISMATCH],
                'severity' => 'critical',
                'hint' => 'Two counts of your pieces disagreed — the platform is looking into it',
            ],
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

    // ------------------------------------------------------------- the charts
    //
    // Counts only, like the rest of the page. They are the one place it looks
    // back rather than at now — trends the owner asked for — and each is read
    // through the scoped Order, so a laundry's charts are its own.

    /**
     * Orders per day: placed, delivered and cancelled, quiet days included as
     * zero — a line that skips a quiet Tuesday draws straight over it.
     *
     * Days by `date()` in SQL, the convention `RevenueReport::daily()` uses, so
     * this chart and the reports cannot disagree about which day something
     * belonged to. Delivered and cancelled are dated by the order log — when it
     * happened — not by the order's last update.
     *
     * @return array<int, array{date: string, placed: int, delivered: int, cancelled: int}>
     */
    public function ordersByDay(int $days = 14): array
    {
        $range = new DateRange(now()->startOfDay()->subDays($days - 1), now()->endOfDay());
        $window = [$range->from, $range->to];

        $placed = Order::whereBetween('created_at', $window)
            ->selectRaw('date(created_at) d, count(*) c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $reached = fn (OrderStatus $status) => $this->reachedStatus($status)
            ->whereBetween('created_at', $window)
            ->selectRaw('date(created_at) d, count(distinct order_id) c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $delivered = $reached(OrderStatus::Delivered);
        $cancelled = $reached(OrderStatus::Cancelled);

        return array_map(fn (string $day) => [
            'date' => $day,
            'placed' => (int) ($placed[$day] ?? 0),
            'delivered' => (int) ($delivered[$day] ?? 0),
            'cancelled' => (int) ($cancelled[$day] ?? 0),
        ], $range->eachDay());
    }

    /**
     * This month's orders by service: the five busiest and the rest together.
     *
     * A donut past six slices stops being read as parts of a whole. Each service
     * keeps one colour slot for good — its place among all services by id — so
     * a busy month does not repaint a quiet service in another's colour.
     *
     * @return array{items: array<int, array{label: string, count: int, slot: int}>, other: int, total: int}
     */
    public function ordersByService(int $shown = 5): array
    {
        $counts = Order::whereBetween('created_at', [now()->startOfMonth(), now()->endOfDay()])
            ->selectRaw('service_id, count(*) c')
            ->groupBy('service_id')
            ->pluck('c', 'service_id')
            ->map(fn ($c) => (int) $c)
            ->sortDesc();

        $services = Service::query()->orderBy('id')->get(['id', 'name']);
        $slotOf = $services->pluck('id')->flip();

        $items = [];
        $other = 0;

        foreach ($counts as $serviceId => $count) {
            $service = $services->firstWhere('id', $serviceId);
            $slot = $slotOf[$serviceId] ?? null;

            // Eight colours, never a ninth: a service past the eighth, or one
            // no longer in the catalogue, is counted with the rest.
            if ($service && $slot !== null && $slot < 8 && count($items) < $shown) {
                $items[] = [
                    'label' => (string) getLocalizedValueDashboard($service, 'name'),
                    'count' => $count,
                    'slot' => $slot + 1,
                ];
            } else {
                $other += $count;
            }
        }

        return ['items' => $items, 'other' => $other, 'total' => $counts->sum()];
    }

    /**
     * This month's ratings, how many at each score — the spread an average hides.
     *
     * @return array<int, int> score (5 down to 1) => ratings
     */
    public function ratingSpread(): array
    {
        $counts = OrderRating::whereBetween('created_at', [now()->startOfMonth(), now()->endOfDay()])
            ->selectRaw('overall s, count(*) c')
            ->groupBy('s')
            ->pluck('c', 's');

        $spread = [];
        foreach ([5, 4, 3, 2, 1] as $score) {
            $spread[$score] = (int) ($counts[$score] ?? 0);
        }

        return $spread;
    }

    /**
     * The log rows of the viewer's own orders reaching one status.
     *
     * The log carries no laundry_id, so it is reached through the scoped Order:
     * a laundry counts its own handovers and nobody else's.
     *
     * @return Builder<OrderStatusLog>
     */
    private function reachedStatus(OrderStatus $status): Builder
    {
        return OrderStatusLog::query()
            ->where('to_status', $status->value)
            ->whereIn('order_id', Order::query()->select('orders.id'));
    }

    /**
     * Orders with a piece count a driver disagreed on and nobody has reviewed —
     * the same number as the sidebar badge and the filter it links to.
     */
    private function openPieceChecks(): int
    {
        return Order::withOpenPieceCheck()->count();
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
