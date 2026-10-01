<?php

namespace App\Modules\Order\Repositories;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\PieceCheckStep;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\PieceDiscrepancy;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\DriverEarning;
use App\Modules\Payment\Models\OrderSettlement;
use App\Modules\Payment\Models\Refund;
use App\Modules\Wallet\Models\WalletTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every order query lives here.
 *
 * The tenant scope on the Order model applies to all of it, so a laundry user
 * calling getAllPaginated() gets its own orders and a super admin gets
 * everything — without a single `where laundry_id` in this class.
 */
class OrderRepository
{
    /**
     * @var array<int, string>
     */
    // `pricing_mode` is in the select list on purpose. A column allow-list that
    // omits it does not fail — `isPerItem()` reads null and answers false, so
    // every catalogued order quietly claims to be quote-priced and the review
    // screen offers to re-price pieces the platform sets the price of.
    private const EAGER = ['customer:id,name,phone', 'laundry:id,name', 'service:id,name,pricing_mode', 'pickupAddress'];

    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Order::with(self::EAGER)->tap($this->markPieceChecks(...))->latest('id')->paginate($perPage);
    }

    /**
     * `piece_check_open` on each row — the list marks an order a driver counted
     * differently, in the same query rather than one per row.
     *
     * @param  Builder<Order>  $query
     */
    private function markPieceChecks(Builder $query): void
    {
        $query->withExists(['pieceDiscrepancies as piece_check_open' => fn ($rows) => PieceDiscrepancy::constrainOpen($rows)]);
    }

    /**
     * Filters the list understands that are not `OrderStatus` cases.
     *
     * Kept as constants because three places have to agree on the spelling: this
     * repository, the dropdown on the list screen, and the route the home page's
     * queue links to.
     */
    public const NEEDS_DRIVER = 'needs_driver';

    public const NEEDS_LAUNDRY = 'needs_laundry';

    public const NEEDS_RESCUE = 'needs_rescue';

    public const NEEDS_PRICE_ANSWER = 'needs_price_answer';

    // A driver's piece count that did not match and nobody has reviewed.
    public const PIECE_MISMATCH = 'piece_mismatch';

    public function search(?string $query, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return Order::with(self::EAGER)
            ->tap($this->markPieceChecks(...))
            ->when($query, fn (Builder $q) => $this->matching($q, $query))
            ->when($status, function (Builder $q) use ($status): void {
                /*
                 * Two of the home page's queue items are not order statuses.
                 *
                 * «Journeys with no driver» is a *task* state: an order sits at
                 * `awaiting_pickup` while one of its four legs waits in the pool
                 * with nobody eligible, so `where('status', 'needs_driver')`
                 * would return nothing at all. «Orders with no laundry» is a
                 * null `laundry_id`, which is likewise not a status.
                 *
                 * Both are filters an operator needs to reach from the queue —
                 * that queue's whole promise is that the number is clickable —
                 * so they are spelled here beside the real statuses rather than
                 * bolted onto `OrderStatus`, which is the vocabulary the apps
                 * and the API share.
                 */
                match ($status) {
                    /*
                     * Through the model rather than `whereHas('tasks', ...)`:
                     * inside that closure the builder is the un-generic
                     * `Builder<Model>`, so `queued()` is invisible to phpstan
                     * and the alternative is an inline `@var` overriding it.
                     * `OrderTask::queued()` keeps the scope as the one
                     * definition of "no driver", stays typed, and compiles to a
                     * single `IN (subquery)` instead of a correlated EXISTS.
                     */
                    self::NEEDS_DRIVER => $q->whereIn('id', OrderTask::queued()->select('order_id')),
                    self::NEEDS_LAUNDRY => $q->unassigned()->active(),
                    // A leg that failed its way out of the pool. Somebody has to
                    // release it and hand it to a driver, and both of those live
                    // on the order's screen.
                    self::NEEDS_RESCUE => $q->whereIn('id', OrderTask::where('status', 'failed')
                        ->where('attempts', '>=', OrderTask::MAX_ATTEMPTS)
                        ->select('order_id')),
                    self::NEEDS_PRICE_ANSWER => $q->whereIn('id', OrderPriceQuery::open()->select('order_id')),
                    self::PIECE_MISMATCH => $q->withOpenPieceCheck(),
                    default => $q->where('status', $status),
                };
            })
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * «طلبات اليوم» — the orders one working view is about, nearest first.
     *
     * Three scopes, each a different question a laundry asks of its day:
     *
     *   - `in_laundry` — the pieces are physically there: the leg that carries
     *     them to the laundry is complete and the leg that takes them away is
     *     not. Read off the legs rather than the status, because
     *     `ready_for_delivery` lasts the whole journey back to the customer and
     *     `picked_up` the whole journey in, so a status test answers «in the
     *     laundry» for bags that are in a van.
     *   - `delivery_today` / `pickup_today` — the customer's chosen day, on the
     *     date asked for. Those columns are plain dates in the customer's own
     *     calendar, so they compare as dates with no timezone arithmetic.
     *
     * Not paginated: it is one day's work and the totals above it are summed
     * over exactly these rows. The tenant scope on `Order` does the rest — a
     * laundry sees its own and nobody else's, with no `where laundry_id` here.
     *
     * @param  array{scope: string, date: string, status: ?string, service_id: ?int, laundry_id: ?int, query: ?string}  $filters
     * @return Collection<int, Order>
     */
    public function todayBoard(array $filters)
    {
        $orders = (new Order)->getTable();
        $slotColumn = $filters['scope'] === 'pickup_today' ? 'pickup_slot_id' : 'delivery_slot_id';
        $dateColumn = $filters['scope'] === 'pickup_today' ? 'pickup_date' : 'delivery_date';

        return Order::query()
            ->select("{$orders}.*")
            // Joined only to order by the window's start; the eager load below
            // is what the view reads.
            ->leftJoin('time_slots as board_slot', 'board_slot.id', '=', "{$orders}.{$slotColumn}")
            ->with([
                'customer:id,name,phone', 'laundry:id,name', 'service:id,name,pricing_mode',
                'pickupAddress', 'deliveryAddress', 'pickupSlot', 'deliverySlot', 'items.item:id,name',
                // Whether each half is done, and when the pieces were collected.
                'tasks:id,order_id,type,status,completed_at',
            ])
            ->when($filters['scope'] === 'in_laundry', fn (Builder $q) => $q
                ->whereIn("{$orders}.id", OrderTask::where('type', TaskType::DeliverToLaundry->value)
                    ->where('status', TaskStatus::Completed->value)->select('order_id'))
                ->whereNotIn("{$orders}.id", OrderTask::where('type', TaskType::CollectFromLaundry->value)
                    ->where('status', TaskStatus::Completed->value)->select('order_id'))
                // Defensive: an order moved on by hand without its legs.
                ->whereNotIn("{$orders}.status", [
                    OrderStatus::Delivered->value, OrderStatus::Completed->value,
                    OrderStatus::Cancelled->value, OrderStatus::Returned->value,
                ]))
            ->when($filters['scope'] !== 'in_laundry', fn (Builder $q) => $q
                ->whereDate("{$orders}.{$dateColumn}", $filters['date']))
            // A cancelled or returned order is not work — unless it is exactly
            // what the filter asked for.
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where("{$orders}.status", $status),
                fn (Builder $q) => $q->whereNotIn("{$orders}.status", [OrderStatus::Cancelled->value, OrderStatus::Returned->value]))
            ->when($filters['service_id'], fn (Builder $q, int $id) => $q->where("{$orders}.service_id", $id))
            ->when($filters['laundry_id'], fn (Builder $q, int $id) => $q->where("{$orders}.laundry_id", $id))
            // Everything the row shows: the code, the customer, the service, the
            // laundry — and the pieces, which is what this screen is read for.
            ->when($filters['query'], fn (Builder $q, string $term) => $this->matching($q, $term, withPieces: true))
            // Nearest first: the day, then the start of the window. A missing
            // day or window sorts last rather than first — it is the least
            // urgent thing on the list only because nobody said when.
            ->orderByRaw("{$orders}.{$dateColumn} is null, {$orders}.{$dateColumn} asc")
            ->orderByRaw('board_slot.start_time is null, board_slot.start_time asc')
            ->orderBy("{$orders}.id")
            ->get();
    }

    /**
     * The orders a search term finds, on either list screen: the code, the
     * customer's name or phone, the service and the laundry — the last two
     * translatable json columns, hence the fold, which a bare `like` cannot do
     * against a binary collation (see Searchable's docblock).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function matching(Builder $query, string $term, bool $withPieces = false): Builder
    {
        $folded = '%'.mb_strtolower($term).'%';
        $json = fn (Builder $relation) => $relation->whereRaw('LOWER(CAST(`name` AS CHAR)) LIKE ?', [$folded]);

        return $query->where(fn (Builder $inner) => $inner
            ->where($query->getModel()->qualifyColumn('code'), 'like', "%{$term}%")
            ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%"))
            ->orWhereHas('service', $json)
            ->orWhereHas('laundry', $json)
            ->when($withPieces, fn (Builder $q) => $q->orWhereHas('items.item', $json)));
    }

    /**
     * The last leg of this order before `$task` that finished with a count —
     * the handover `$task` is measured against.
     */
    public function lastCountedLegBefore(OrderTask $task): ?OrderTask
    {
        return OrderTask::query()
            ->where('order_id', $task->order_id)
            ->where('sequence', '<', $task->sequence)
            ->where('status', TaskStatus::Completed->value)
            ->whereNotNull('piece_count')
            ->orderByDesc('sequence')
            ->first();
    }

    /**
     * One of the order's legs, if it has been completed — the handover to the
     * laundry, when its review comes to be checked against it.
     */
    public function completedLeg(Order $order, TaskType $type): ?OrderTask
    {
        return OrderTask::query()
            ->where('order_id', $order->id)
            ->where('type', $type->value)
            ->where('status', TaskStatus::Completed->value)
            ->first();
    }

    /**
     * The order's latest disagreement at the laundry's review, open or
     * reviewed — a later review updates an open one, and is measured against
     * what the platform settled on a reviewed one.
     */
    public function latestReviewDiscrepancy(Order $order): ?PieceDiscrepancy
    {
        return PieceDiscrepancy::query()
            ->where('order_id', $order->id)
            ->where('step', PieceCheckStep::LaundryReview->value)
            ->latest('id')
            ->first();
    }

    /**
     * A leg re-read under a row lock, inside the caller's transaction — so the
     * same handover confirmed twice at once is completed once.
     */
    public function lockTask(int $id): ?OrderTask
    {
        return OrderTask::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * A disagreement by id, **through the tenant-scoped `Order`** — it carries
     * no `laundry_id` of its own, so another laundry's id is simply not found.
     */
    public function findDiscrepancy(int|string $id): PieceDiscrepancy
    {
        return PieceDiscrepancy::query()
            ->whereIn('order_id', Order::query()->select('id'))
            ->findOrFail($id);
    }

    /**
     * Re-read under a row lock, inside the caller's transaction — so two
     * «تمت المراجعة» at once cannot both find it open.
     */
    public function lockDiscrepancy(int $id): ?PieceDiscrepancy
    {
        return PieceDiscrepancy::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findById(int|string $id): Order
    {
        return Order::with([
            ...self::EAGER,
            'deliveryAddress', 'pickupSlot', 'deliverySlot',
            'items.item', 'statusLogs.actor:id,name', 'media',
            'priceQueries.customer:id,name', 'priceQueries.responder:id,name',
            'tasks.driver:id,name', 'payments',
            // The banner, the transport table's red counts and the reviewed
            // list all read these — one query, not one per leg.
            'pieceDiscrepancies.task.driver:id,name', 'pieceDiscrepancies.resolver:id,name',
            // The detail screen names the «عروض متميزة» card this order came
            // through; without it that one line is its own query.
            'offer',
        ])->findOrFail($id);
    }

    /**
     * Unassigned orders are outside every tenant's scope, so this is a super-admin
     * view by construction — which is the point: nobody should be triaging work
     * that has not been given to them.
     */
    public function unassigned(int $perPage = 15): LengthAwarePaginator
    {
        return Order::with(self::EAGER)->unassigned()->latest('id')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    /**
     * Whether anything financial has already happened on this order.
     *
     * Every one of these tables cascades when an order row goes, so this is the
     * difference between deleting an order and deleting the record that money
     * moved. Named per cause rather than returned as a bool: the operator is
     * told which one refused them, and «this order has a settlement» is a
     * different instruction from «this order has been paid».
     *
     * `wallet_transactions` is in here for the opposite reason — it has **no**
     * foreign key on the order, because it points at its source
     * polymorphically. It would not cascade; it would be left behind naming a
     * row that no longer exists.
     *
     * A `pending` or `failed` payment is not a trace. Nothing moved: it is a row
     * the gateway wrote on the way to an outcome that never arrived, and an
     * order can collect one without anybody having been charged.
     */
    public function moneyTrace(Order $order): ?string
    {
        if ($order->payments()->whereNotIn('status', [
            PaymentStatus::Pending->value,
            PaymentStatus::Failed->value,
        ])->exists()) {
            return 'payment';
        }

        if (Refund::where('order_id', $order->id)->exists()) {
            return 'refund';
        }

        if (OrderSettlement::where('order_id', $order->id)->exists()) {
            return 'settlement';
        }

        if (DriverEarning::where('order_id', $order->id)->exists()) {
            return 'earning';
        }

        if (WalletTransaction::where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->exists()) {
            return 'wallet';
        }

        return null;
    }

    /**
     * Deletes the order row, and with it the eleven tables that cascade off it.
     *
     * Unguarded on purpose — `OrderDeletionGuard` decides, and it is called by
     * the service that calls this. A repository that second-guessed the caller
     * would put the rule in two places.
     */
    public function delete(int|string $id): bool
    {
        return (bool) $this->findById($id)->delete();
    }

    public function counts(): array
    {
        return [
            'total' => Order::count(),
            'active' => Order::active()->count(),
            'unassigned' => Order::unassigned()->count(),
        ];
    }
}
