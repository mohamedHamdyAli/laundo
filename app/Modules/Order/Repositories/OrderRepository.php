<?php

namespace App\Modules\Order\Repositories;

use App\Modules\Order\Models\Order;
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

    public function search(?string $query, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        return Order::with(self::EAGER)
            ->when($query, function (Builder $q) use ($query) {
                $q->where(function (Builder $inner) use ($query) {
                    $inner->where('code', 'like', "%{$query}%")
                        ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%"));
                });
            })
            ->when($status, fn (Builder $q) => $q->where('status', $status))
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
