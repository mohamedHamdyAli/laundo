<?php

namespace App\Modules\Order\Repositories;

use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderPriceQuery;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
        return Order::with(self::EAGER)->latest('id')->paginate($perPage);
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

    public function search(?string $query, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return Order::with(self::EAGER)
            ->when($query, function (Builder $q) use ($query) {
                $q->where(function (Builder $inner) use ($query) {
                    $inner->where('code', 'like', "%{$query}%")
                        ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%"))
                        // The SERVICE column on the list screen, and the laundry
                        // shown beneath it. Both are translatable json columns —
                        // hence the fold, which a bare `like` cannot do against a
                        // binary collation. See Searchable's docblock.
                        ->orWhereHas('service', fn (Builder $s) => $s->whereRaw(
                            'LOWER(CAST(`name` AS CHAR)) LIKE ?', ['%'.mb_strtolower($query).'%']
                        ))
                        ->orWhereHas('laundry', fn (Builder $l) => $l->whereRaw(
                            'LOWER(CAST(`name` AS CHAR)) LIKE ?', ['%'.mb_strtolower($query).'%']
                        ));
                });
            })
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
                    default => $q->where('status', $status),
                };
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function findById(int|string $id): Order
    {
        return Order::with([
            ...self::EAGER,
            'deliveryAddress', 'pickupSlot', 'deliverySlot',
            'items.item', 'statusLogs.actor:id,name', 'media',
            'priceQueries.customer:id,name', 'priceQueries.responder:id,name',
            'tasks.driver:id,name', 'payments',
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

    public function counts(): array
    {
        return [
            'total' => Order::count(),
            'active' => Order::active()->count(),
            'unassigned' => Order::unassigned()->count(),
        ];
    }
}
