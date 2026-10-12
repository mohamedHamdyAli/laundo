<?php

namespace App\Modules\Driver\Repositories;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * All queries go through the Driver model, so its role scope always applies —
 * which is what stops /admin/driver/show/{id} rendering a customer or a laundry
 * owner given their id.
 */
class DriverRepository
{
    public function getAllPaginated($perPage = 15)
    {
        return Driver::with(['profile.bonusRule', 'zones'])->latest()->paginate($perPage);
    }

    public function search($query, $perPage = 15)
    {
        return Driver::with(['profile.bonusRule', 'zones'])
            ->search($query, [
                'name', 'phone', 'email',
                // The VEHICLE column on the list screen.
                'profile.vehicle_type', 'profile.plate_number',
                // The AREAS column, through driver_zones.
                'zones.name',
            ])
            ->latest()
            ->paginate($perPage);
    }

    public function findById($id)
    {
        return Driver::with(['profile.bonusRule', 'zones'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return Driver::create($data);
    }

    public function update($id, array $data)
    {
        $driver = $this->findById($id);
        $driver->update($data);

        return $driver;
    }

    public function delete($id)
    {
        return $this->findById($id)->delete();
    }

    /**
     * Every driver for «خريطة السواقين», with the legs they are carrying now.
     *
     * Asked every few seconds by an open map, so everything the card shows is
     * eager-loaded here and the query count does not grow with the drivers.
     *
     * **The column lists are what the card reads** — a column left out of a
     * constrained eager load reads as null, not as an error, and null is also
     * how the card says «no pin». `customer()` needs `user_id` on the order and
     * the laundry legs need `lat`/`lng` on the laundry.
     *
     * @return Collection<int, Driver>
     */
    public function forMap(): Collection
    {
        return Driver::with([
            'profile',
            'zones',
            'tasks' => fn ($q) => $q
                ->whereIn('status', [TaskStatus::Assigned->value, TaskStatus::Started->value])
                // Through the tenant-scoped Order, as the dispatch board does.
                // The map refuses anybody inside a laundry, so this is the whole
                // table in practice — and the right answer if that ever changes.
                ->whereIn('order_id', Order::query()->select('id'))
                ->inDueOrder(),
            'tasks.order:id,code,status,user_id,laundry_id,pickup_address_id,delivery_address_id',
            'tasks.order.customer:id,name',
            'tasks.order.pickupAddress',
            'tasks.order.deliveryAddress',
            'tasks.order.laundry:id,name,address,lat,lng',
        ])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The legs nobody holds, for the waiting pins — the dispatch board's own
     * definition (`needingAPerson()`), through the scoped Order.
     *
     * Bounded: the map draws one pin per order, and a backlog large enough to
     * pass this is a backlog the dispatch board is the screen for.
     *
     * @return Collection<int, OrderTask>
     */
    public function waitingLegsForMap(int $limit = 1000): Collection
    {
        return $this->waitingLegs()
            ->orderBy('order_id')
            ->orderBy('sequence')
            ->limit($limit)
            ->get();
    }

    /**
     * One waiting leg, or null when it is not waiting any more (or not ours).
     */
    public function waitingLegForMap(int $id): ?OrderTask
    {
        return $this->waitingLegs()->whereKey($id)->first();
    }

    /**
     * @return Builder<OrderTask>
     */
    private function waitingLegs(): Builder
    {
        return OrderTask::query()
            ->whereIn('order_id', Order::query()->select('id'))
            ->needingAPerson()
            ->with([
                'order:id,code,status,user_id,laundry_id,pickup_address_id,delivery_address_id',
                'order.customer:id,name',
                'order.pickupAddress',
                'order.deliveryAddress',
                'order.laundry:id,name,address,lat,lng',
            ]);
    }
}
