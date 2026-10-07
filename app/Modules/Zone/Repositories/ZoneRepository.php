<?php

namespace App\Modules\Zone\Repositories;

use App\Modules\Address\Models\Address;
use App\Modules\Order\Models\Order;
use App\Modules\Zone\Models\Zone;
use App\Support\Geo\Polygon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ZoneRepository
{
    public function getAllPaginated($perPage = 15)
    {
        return Zone::with('city')->orderBy('city_id')->orderBy('sort_order')->paginate($perPage);
    }

    public function search($query, $perPage = 15)
    {
        return Zone::with('city')->search($query, ['name', 'city.name', 'sort_order'])->orderBy('sort_order')->paginate($perPage);
    }

    public function findById($id)
    {
        return Zone::with('city')->findOrFail($id);
    }

    public function allActive()
    {
        return Zone::with('city')->where('status', 'active')->orderBy('city_id')->orderBy('sort_order')->get();
    }

    /**
     * A zone by id, switched on or off. Which zone an address is in does not
     * depend on the switch — whether orders are taken there does
     * (`Address::isCovered()`), so a zone switched off is still a zone.
     */
    public function find(int $id): ?Zone
    {
        return Zone::query()->find($id);
    }

    /**
     * Drawn zones whose bounding box holds the pin — the only ones worth asking
     * the exact question of. Zones may not overlap (inactive ones included),
     * so this is almost always one row, often none.
     *
     * @return Collection<int, Zone>
     */
    public function drawnAround(float $lat, float $lng): Collection
    {
        return $this->claiming()
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->get();
    }

    /**
     * The zones that may claim any address inside these boxes — read once for
     * a whole redraw, so each address is checked in memory rather than asked
     * of the database again.
     *
     * @param  list<array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}>  $boxes
     * @return Collection<int, Zone>
     */
    public function claimingWithin(array $boxes): Collection
    {
        if ($boxes === []) {
            return new Collection;
        }

        return $this->claiming()
            ->where(function ($query) use ($boxes) {
                foreach ($boxes as $box) {
                    $query->orWhere(fn ($q) => $this->meeting($q, $box));
                }
            })
            ->get();
    }

    /**
     * Every drawn zone but one whose box meets this one — what a new drawing
     * must not overlap. Inactive ones too: switched back on, an overlap would
     * reappear with nobody having drawn anything. `$lock` holds them for the
     * caller's transaction, so two drawings saved at once cannot both pass
     * against the other's old shape.
     *
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null  $box
     * @return Collection<int, Zone>
     */
    public function drawnExcept(?int $id, ?array $box = null, bool $lock = false): Collection
    {
        return Zone::query()
            ->whereNotNull('boundary')
            ->when($id, fn ($q) => $q->whereKeyNot($id))
            ->when($box, fn ($q) => $this->meeting($q, $box))
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /**
     * Whether an address has an order still under way — collected or not yet,
     * but not finished — that its zone is routing right now.
     */
    public function hasOrderInProgress(Address $address): bool
    {
        // Every tenant's orders: this is the platform deciding whether it may
        // move a customer's address, not a list anybody reads.
        return Order::withoutGlobalScopes()
            ->active()
            ->where(fn ($q) => $q->where('pickup_address_id', $address->id)->orWhere('delivery_address_id', $address->id))
            ->exists();
    }

    /**
     * Every drawn zone, switched on or off, in any city.
     *
     * Until 2026-10-07 only an active zone in an active city claimed a pin.
     * That made switching a zone off erase it from any address saved or edited
     * meanwhile — and let a pin inside a zone switched off be filed under
     * another zone the app picked, whose order then went through. Where a pin
     * is does not change with a switch; whether orders are taken there is
     * `Address::isCovered()`.
     *
     * @return Builder<Zone>
     */
    private function claiming(): Builder
    {
        return Zone::query()->whereNotNull('boundary');
    }

    /**
     * Boxes that meet — each reaches the other on both axes, with the
     * drawing's own tolerance so a neighbour along a shared street is found.
     *
     * @param  Builder<Zone>  $query
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}  $box
     * @return Builder<Zone>
     */
    private function meeting(Builder $query, array $box): Builder
    {
        $t = Polygon::TOLERANCE;

        return $query
            ->where('min_lat', '<=', $box['max_lat'] + $t)->where('max_lat', '>=', $box['min_lat'] - $t)
            ->where('min_lng', '<=', $box['max_lng'] + $t)->where('max_lng', '>=', $box['min_lng'] - $t);
    }

    /**
     * Addresses a zone's drawing may have taken in or let go: those inside
     * either bounding box (the drawing before and after), and those already in
     * the zone.
     *
     * @param  list<array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}>  $boxes
     * @return Collection<int, Address>
     */
    public function addressesNear(int $zoneId, array $boxes): Collection
    {
        return Address::query()
            ->whereNotNull('lat')->whereNotNull('lng')
            ->where(function ($query) use ($zoneId, $boxes) {
                $query->where('zone_id', $zoneId);

                foreach ($boxes as $box) {
                    $query->orWhere(fn ($q) => $q
                        ->whereBetween('lat', [$box['min_lat'], $box['max_lat']])
                        ->whereBetween('lng', [$box['min_lng'], $box['max_lng']]));
                }
            })
            ->get();
    }

    public function create(array $data)
    {
        return Zone::create($data);
    }

    public function update($id, array $data)
    {
        $zone = Zone::findOrFail($id);
        $zone->update($data);

        return $zone;
    }

    public function delete($id)
    {
        return Zone::findOrFail($id)->delete();
    }
}
