<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dispatch board.
 *
 * Until this existed, a leg could only be assigned from inside its own order's
 * page. Finding the work meant filtering the order list to «has a journey with
 * no driver», opening an order, reading its Transport table, assigning, going
 * back, opening the next one. The home page counted fifteen waiting journeys
 * and the only way to reach them was one order at a time.
 *
 * The figures were already being computed and thrown away:
 * `OperationsReport::queuedTasks()` builds order code, leg, waiting hours and
 * attempts for up to fifty waiting legs, and the operations report renders the
 * count and discards the rows.
 *
 * **What counts as needing a person:** a leg with no driver that is not
 * finished. That is one filter for both things the home queue counts
 * separately — a pending leg nobody has taken, and a failed one, because
 * `TaskService` nulls `driver_id` when a task fails. A completed leg is done and
 * an assigned one has somebody, so neither belongs here.
 *
 * Rooted in the tenant-scoped `Order`, so a laundry sees only its own legs.
 */
class dispatchBoardService
{
    public function __construct(
        private readonly DriverDispatcher $dispatcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shredData(?string $search = null, ?string $leg = null): array
    {
        $legs = $this->waiting($search, $leg);

        // Once. `candidates()` runs a driver query plus a count per driver, and
        // `whyNobodyEligible()` opens by calling it again — so working it out
        // per consumer would have this board doing the same expensive pass three
        // times for every row, which is the fault it was built to remove.
        $candidates = $this->candidates($legs);

        return [
            'legs' => $legs,
            'candidates' => $candidates,
            'blockers' => $this->blockers($legs, $candidates),
            'driverLoads' => $this->driverLoads($candidates),
            'openLegsByOrder' => $this->openLegsByOrder($legs),
            'counts' => $this->counts(),
            'activeLeg' => $leg,
            // So the empty state can tell «the board is clear» from «your search
            // matched nothing». Claiming every journey has a driver because a
            // search missed would be a lie the operator acts on.
            'activeSearch' => $search,
            'legTypes' => TaskType::cases(),
        ];
    }

    /**
     * Every leg waiting for a person, longest wait first.
     *
     * Longest first because that is the dispatcher's own priority — a journey
     * that has been sitting for six hours is the one somebody has to explain.
     *
     * @return LengthAwarePaginator<int, OrderTask>
     */
    public function waiting(?string $search = null, ?string $leg = null, int $perPage = 20): LengthAwarePaginator
    {
        return OrderTask::query()
            // Through the tenant-scoped Order, so a laundry owner sees its own.
            ->whereIn('order_id', Order::query()->select('id'))
            ->whereNull('driver_id')
            ->where('status', '!=', 'completed')
            ->with([
                // `user_id` is in the select because `customer()` is a
                // `belongsTo(User::class, 'user_id')`: a column list on the
                // parent that leaves out the foreign key gives every row a null
                // relation, and the Customer column silently read «—».
                'order:id,code,status,user_id,laundry_id,pickup_address_id,delivery_address_id',
                'order.customer:id,name,phone',
                'order.pickupAddress.zone',
                'order.deliveryAddress.zone',
            ])
            ->when($leg, fn (Builder $q) => $q->where('type', $leg))
            ->when($search, function (Builder $q) use ($search): void {
                $term = '%'.mb_strtolower(trim((string) $search)).'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->whereHas('order', fn (Builder $o) => $o->whereRaw('LOWER(`code`) LIKE ?', [$term]))
                        ->orWhereHas('order.customer', fn (Builder $c) => $c
                            ->whereRaw('LOWER(`name`) LIKE ?', [$term])
                            ->orWhere('phone', 'like', $term));
                });
            })
            ->orderBy('created_at')
            ->paginate($perPage);
    }

    /**
     * The headline figures, and the reason they are separate.
     *
     * A leg nobody has taken yet and a leg that failed twice are both waiting,
     * and they are not the same problem: the first needs a driver, the second
     * needs somebody to find out what happened. The board shows both and counts
     * them apart.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $base = fn () => OrderTask::query()
            ->whereIn('order_id', Order::query()->select('id'))
            ->whereNull('driver_id')
            ->where('status', '!=', 'completed');

        return [
            'waiting' => $base()->count(),
            'never_taken' => $base()->where('status', 'pending')->count(),
            'failed' => $base()->where('status', 'failed')->count(),
            'orders' => $base()->distinct()->count('order_id'),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, OrderTask>  $legs
     * @return array<int, array<int, Driver>>
     */
    private function candidates(LengthAwarePaginator $legs): array
    {
        $out = [];

        // `->items()` rather than iterating the paginator: the *contract* is not
        // declared iterable, only the concrete class is, so a `foreach` over it
        // is a phpstan error even though it works at runtime.
        foreach ($legs->items() as $leg) {
            $out[$leg->id] = $this->dispatcher->candidates($leg);
        }

        return $out;
    }

    /**
     * @param  LengthAwarePaginator<int, OrderTask>  $legs
     * @param  array<int, array<int, Driver>>  $candidates
     * @return array<int, array{reason: string, params: array<string, int|string>}>
     */
    private function blockers(LengthAwarePaginator $legs, array $candidates): array
    {
        $out = [];

        foreach ($legs->items() as $leg) {
            // Only asked where there is something to explain. A leg with a
            // candidate has no blocker by definition, and asking anyway would
            // re-run the whole eligibility pass to be told so.
            if (($candidates[$leg->id] ?? []) !== []) {
                continue;
            }

            $reason = $this->dispatcher->whyNobodyEligible($leg);

            if ($reason !== null) {
                $out[$leg->id] = $reason;
            }
        }

        return $out;
    }

    /**
     * How many orders each candidate on this page is already carrying.
     *
     * One query for the whole page. `DriverDispatcher::activeOrders()` runs a
     * `COUNT(DISTINCT order_id)` per call and `candidates()` already invokes it
     * from inside a `usort` comparator — calling it again per row per driver is
     * how a board of twenty legs would cost a thousand queries.
     *
     * @param  array<int, array<int, Driver>>  $candidates
     * @return array<int, array{load: int, cap: int|null}>
     */
    private function driverLoads(array $candidates): array
    {
        $drivers = [];

        foreach ($candidates as $forLeg) {
            foreach ($forLeg as $driver) {
                $drivers[$driver->id] = $driver;
            }
        }

        if ($drivers === []) {
            return [];
        }

        $counted = OrderTask::query()
            ->whereIn('driver_id', array_keys($drivers))
            ->open()
            ->select('driver_id', 'order_id')
            ->distinct()
            ->get()
            ->groupBy('driver_id')
            ->map(fn ($rows) => $rows->count());

        $out = [];

        foreach ($drivers as $id => $driver) {
            $out[$id] = [
                'load' => (int) ($counted[$id] ?? 0),
                'cap' => $driver->profile?->max_concurrent_orders,
            ];
        }

        return $out;
    }

    /**
     * How many open legs each order on this page still has.
     *
     * Feeds the «and the other N legs» tickbox. Counted across the whole order
     * rather than the page, because the offer is about the order: two of its
     * legs might be on the next page and they would still be assigned.
     *
     * @param  LengthAwarePaginator<int, OrderTask>  $legs
     * @return array<int, int>
     */
    private function openLegsByOrder(LengthAwarePaginator $legs): array
    {
        $orderIds = collect($legs->items())->pluck('order_id')->unique();

        if ($orderIds->isEmpty()) {
            return [];
        }

        return OrderTask::query()
            ->whereIn('order_id', $orderIds)
            ->whereNull('driver_id')
            ->where('status', '!=', 'completed')
            ->selectRaw('order_id, COUNT(*) as open_legs')
            ->groupBy('order_id')
            ->pluck('open_legs', 'order_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
