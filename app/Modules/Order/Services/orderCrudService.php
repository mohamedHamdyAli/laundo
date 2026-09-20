<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Item\Models\Item;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\User\Models\User;
use App\Services\Routing\Coordinate;
use App\Services\Routing\RouteLeg;
use App\Services\Routing\RoutingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The dashboard's view of orders.
 *
 * Read-heavy on purpose. An order is created by a customer, moved by drivers and
 * priced by the laundry — the dashboard's job in P6 is to *see* orders and to
 * assign the ones nothing covered. Piece review and final pricing are P7; the
 * transport legs are P8.
 *
 * There is no addNew(), and that is deliberate: an order is placed by a
 * customer, not invented by an operator. Cancellation goes through the state
 * machine, which leaves a trace.
 *
 * deleteRecord() exists but is not a CRUD delete — it is the narrow escape
 * hatch `OrderDeletionGuard` describes, for the order nobody has acted on and
 * no money has touched. Everything with a history is still un-erasable.
 */
class orderCrudService
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderService $orderService,
        private readonly OrderDeletionGuard $guard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shredData($id = null): array
    {
        // A `?status=` in the URL filters the first render too, not only the AJAX
        // refresh. Without this a deep link to «الطلبات المتأخرة» silently shows
        // everything, which is worse than showing nothing.
        $status = request()->get('status');

        $data = [
            'orders' => $status
                ? $this->orders->search(null, $status)
                : $this->orders->getAllPaginated(),
            'activeStatus' => $status,
            'statuses' => OrderStatus::cases(),
            // Named rather than repeated as literals: the dropdown, the
            // repository and the home page's queue link all have to spell these
            // the same way or the filter silently returns everything.
            'needsDriver' => OrderRepository::NEEDS_DRIVER,
            'needsLaundry' => OrderRepository::NEEDS_LAUNDRY,
            'needsRescue' => OrderRepository::NEEDS_RESCUE,
            'needsPriceAnswer' => OrderRepository::NEEDS_PRICE_ANSWER,
            'counts' => $this->orders->counts(),
        ];

        if ($id) {
            $row = $this->orders->findById($id);
            $data['row'] = $row;

            // Only offered while the order can still be moved, and only for the
            // laundries that actually cover it — an operator should not be able to
            // hand an order to a laundry that does not serve the zone.
            $data['assignable'] = $row->status->isInCustody()
                ? []
                : $this->assignableFor($row);

            // The same decision the assigner took, with its reasoning kept: what
            // each candidate measured, how full it is, and what it would cost.
            // An operator overriding the automatic choice should be disagreeing
            // with something they can read.
            $data['assignment'] = $row->status->isInCustody()
                ? null
                : $this->assignmentPanel($row);

            // Only built when the form will actually render — it costs a query
            // over the whole price matrix for the service.
            $data['reviewItems'] = $row->status->isReviewable()
                ? $this->reviewItems($row)
                : [];

            // Who could take each unfinished leg. Computed per task because
            // eligibility is per zone and per city, and the four legs do not all
            // happen in the same place.
            $data['taskCandidates'] = $this->taskCandidates($row);
            // Paired with the candidates: when a leg has none, the screen says
            // *why* rather than leaving the operator to guess between five
            // unrelated causes with five different remedies.
            $data['taskBlockers'] = $this->taskBlockers($row);
            // What each candidate is already carrying, so the picker can say it.
            $data['driverLoads'] = $this->driverLoads($data['taskCandidates']);
            // The full guard, money half included — one order in hand is worth
            // the five existence queries the list cannot afford per row. Null
            // means the delete button is drawn; anything else is the sentence
            // shown in its place.
            $data['deletionBlocker'] = $this->guard->blocker($row);
        }

        return $data;
    }

    public function search($query, $perPage = 15)
    {
        return $this->orders->search($query, request()->get('status'), $perPage);
    }

    /**
     * The laundries an operator may hand this order to.
     *
     * @return array<int, Laundry>
     */
    public function assignableFor(Order $order): array
    {
        if (! $order->pickupAddress || ! $order->service) {
            return [];
        }

        return app(LaundryAssigner::class)->candidates($order->pickupAddress, $order->service);
    }

    /**
     * Everything the assign panel draws.
     *
     * Three things an operator needs and had none of: how far each laundry
     * actually is by road, how full it already is in the window this customer
     * chose, and how far the driver who will carry the clothes there is from
     * it. Without them the dropdown was a list of names and «choose one» meant
     * «guess».
     *
     * The driver measured against is the one on the **`deliver_to_laundry`**
     * leg, because that is the leg that drives to a laundry. When no driver is
     * on it yet the card says so rather than showing a number for somebody who
     * was picked for a different journey.
     *
     * @return array<string, mixed>|null
     */
    public function assignmentPanel(Order $order): ?array
    {
        if (! $order->pickupAddress || ! $order->service) {
            return null;
        }

        $evaluation = app(LaundryAssigner::class)->evaluate(
            $order->pickupAddress,
            $order->service,
            $order->pickup_slot_id,
            $order->pickup_date,
        );

        if ($evaluation['candidates'] === []) {
            return $evaluation + ['driver' => null, 'driver_legs' => [], 'fees' => []];
        }

        $laundries = array_map(fn (array $row) => $row['laundry'], $evaluation['candidates']);
        $driver = $this->carryingDriver($order);

        return $evaluation + [
            'driver' => $driver,
            'driver_legs' => $this->driverLegsTo($driver, $laundries),
            'fees' => $this->feesFor($order, $laundries),
        ];
    }

    /**
     * The driver on the leg that drives to the laundry, if one is on it yet.
     */
    private function carryingDriver(Order $order): ?Driver
    {
        // `driver.profile` only: Driver extends User, so the name is on the
        // model itself and there is no `user` relation to load.
        return OrderTask::with(['driver.profile'])
            ->where('order_id', $order->id)
            ->where('type', TaskType::DeliverToLaundry)
            ->first()?->driver;
    }

    /**
     * Road distance from that driver's last known position to each candidate.
     *
     * Empty when there is no driver or the driver has never reported a
     * position. A driver who has not pinged is not at the depot — they are
     * unknown, and a screen that drew 0 km would be inventing it.
     *
     * @param  array<int, Laundry>  $laundries
     * @return array<int, RouteLeg>
     */
    private function driverLegsTo(?Driver $driver, array $laundries): array
    {
        $profile = $driver?->profile;
        $origin = $profile ? Coordinate::from($profile->last_lat, $profile->last_lng) : null;

        if ($origin === null) {
            return [];
        }

        $destinations = [];

        foreach ($laundries as $laundry) {
            $point = Coordinate::from($laundry->lat, $laundry->lng);

            if ($point !== null) {
                $destinations[$laundry->id] = $point;
            }
        }

        return app(RoutingService::class)->matrix($origin, $destinations);
    }

    /**
     * What the delivery fee would come to for each candidate.
     *
     * The same assembler the assignment itself uses, so the number on the card
     * is the number the operator gets after pressing the button — and not a
     * second calculation that can drift from the first.
     *
     * @param  array<int, Laundry>  $laundries
     * @return array<int, float|null>
     */
    private function feesFor(Order $order, array $laundries): array
    {
        $pricing = app(OrderPricing::class);
        $out = [];

        foreach ($laundries as $laundry) {
            $out[$laundry->id] = $pricing->deliveryFeeFor(
                $laundry,
                $order->pickupAddress,
                $order->deliveryAddress,
            )['fee'];
        }

        return $out;
    }

    public function assign(int|string $id, int $laundryId, ?User $actor = null): Order
    {
        return $this->orderService->assignLaundry($this->orders->findById($id), $laundryId, $actor);
    }

    /**
     * Whether the delete button should be drawn, and what to say when it should
     * not. Straight through to the guard so the screens and this service are
     * asking one question of one object.
     */
    public function deletionBlocker(Order $order): ?string
    {
        return $this->guard->blocker($order);
    }

    /**
     * Erases an order — and, by cascade, the eleven tables hanging off it.
     *
     * The guard runs **here**, inside the transaction, and not only in the view
     * that drew the button. A screen left open while the order was collected
     * would otherwise post a delete the list still believed was allowed, and by
     * then the clothes are in a van.
     *
     * @throws RuntimeException the guard's reason, ready to show
     */
    public function deleteRecord(int|string $id): void
    {
        DB::transaction(function () use ($id): void {
            $order = $this->orders->findById($id);

            $blocker = $this->guard->blocker($order);

            if ($blocker !== null) {
                throw new RuntimeException($blocker);
            }

            $this->orders->delete($order->id);
        });
    }

    /**
     * Deletes several orders, and reports honestly on the ones it would not.
     *
     * **Not one transaction.** Each order is judged and erased on its own, so a
     * selection of twenty that contains one collected order still deletes the
     * nineteen. Rolling the lot back because of one would make the feature
     * useless for the job it exists for — clearing a list — and would teach the
     * operator to select rows one at a time, which is what they were doing
     * before.
     *
     * The refusals come back keyed by order **code**, not id: the operator
     * selected «#10011», and an id means nothing to them.
     *
     * @param  array<int, int|string>  $ids
     * @return array{deleted: int, refused: array<string, string>}
     */
    public function deleteMany(array $ids): array
    {
        $deleted = 0;
        $refused = [];

        foreach ($ids as $id) {
            $order = $this->orders->findById($id);

            try {
                $this->deleteRecord($order->id);
                $deleted++;
            } catch (RuntimeException $e) {
                $refused['#'.$order->code] = $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'refused' => $refused];
    }

    /**
     * Eligible drivers for each unfinished leg.
     *
     * @return array<int, array<int, Driver>>
     */
    private function taskCandidates(Order $order): array
    {
        $dispatcher = app(DriverDispatcher::class);
        $out = [];

        foreach ($order->tasks as $task) {
            if (! $task->status->isFinished()) {
                $out[$task->id] = $dispatcher->candidates($task);
            }
        }

        return $out;
    }

    /**
     * How many orders each candidate is already carrying, and their cap.
     *
     * The picker used to list bare names. The candidates arrive sorted
     * least-loaded-first, which is a real decision the dispatcher makes and
     * which the operator could not see — so two drivers looked
     * interchangeable when one was one order off their limit.
     *
     * Counted in **one** query for every candidate on the page rather than by
     * calling `activeOrders()` per driver. That method runs a
     * `COUNT(DISTINCT order_id)` each time and `candidates()` already calls it
     * from inside a `usort` comparator, so a busy zone was costing hundreds of
     * queries to draw one order.
     *
     * @param  array<int, array<int, Driver>>  $candidates
     * @return array<int, array{load: int, cap: int|null}> keyed by driver id
     */
    private function driverLoads(array $candidates): array
    {
        $drivers = [];

        foreach ($candidates as $forTask) {
            foreach ($forTask as $driver) {
                $drivers[$driver->id] = $driver;
            }
        }

        if ($drivers === []) {
            return [];
        }

        // One read of the distinct (driver, order) pairs, grouped in PHP.
        // `distinct()->count()` over two columns cannot give a per-driver figure
        // in one aggregate, and this is a handful of rows either way.
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
     * Why each unfinished leg has nobody to give it to.
     *
     * Only the legs that actually have no candidate get an entry, so the view
     * can treat presence as "there is something to explain".
     *
     * @return array<int, array{reason: string, params: array<string, int|string>}>
     */
    private function taskBlockers(Order $order): array
    {
        $dispatcher = app(DriverDispatcher::class);
        $out = [];

        foreach ($order->tasks as $task) {
            if ($task->status->isFinished()) {
                continue;
            }

            $reason = $dispatcher->whyNobodyEligible($task);

            if ($reason !== null) {
                $out[$task->id] = $reason;
            }
        }

        return $out;
    }

    /**
     * The rows of the review form.
     *
     * **Every** piece the service is priced for, not just the ones the customer
     * listed — «تم العثور على قطعة إضافية أثناء المراجعة» is the case this screen
     * exists for, and a form that can only subtract cannot record it.
     *
     * Pre-filled from the previous count so the job is correcting a list rather
     * than building one: the customer's own basket on a first review, and the
     * last review's numbers on a re-count.
     *
     * @return array<int, array{item: Item, price: float|null, estimated_qty: int, final_qty: int}>
     */
    private function reviewItems(Order $order): array
    {
        // «تنظيف جاف» has no matrix by design, so there is nothing to filter the
        // catalogue by: every active piece is offered and the laundry prices the
        // ones that actually arrived. For a catalogued service the matrix is both
        // the price list and the list of pieces the service handles at all.
        $quoted = $order->service && ! $order->service->isPerItem();

        $prices = $quoted
            ? collect()
            : ItemPrice::where('service_id', $order->service_id)->pluck('price', 'item_id');

        if (! $quoted && $prices->isEmpty()) {
            return [];
        }

        $estimated = $order->items->where('phase', 'estimated')->pluck('qty', 'item_id');
        $final = $order->items->where('phase', 'final')->pluck('qty', 'item_id');

        $items = Item::query()
            ->when(! $quoted, fn ($q) => $q->whereIn('id', $prices->keys()))
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $estimatedQty = (int) ($estimated[$item->id] ?? 0);

            $rows[] = [
                'item' => $item,
                // Null, not zero. Zero is a price somebody chose; null is the
                // empty box the laundry has to fill in.
                'price' => $quoted
                    ? ($final->has($item->id) ? (float) $order->items
                        ->where('phase', 'final')
                        ->firstWhere('item_id', $item->id)?->unit_price : null)
                    : (float) $prices[$item->id],
                'estimated_qty' => $estimatedQty,
                'final_qty' => (int) ($final[$item->id] ?? $estimatedQty),
            ];
        }

        // The pieces the customer actually asked for come first; the rest are
        // there to be found, not to be scrolled past.
        usort($rows, fn ($a, $b) => ($b['estimated_qty'] <=> $a['estimated_qty'])
            ?: ($a['item']->sort_order <=> $b['item']->sort_order));

        return $rows;
    }
}
