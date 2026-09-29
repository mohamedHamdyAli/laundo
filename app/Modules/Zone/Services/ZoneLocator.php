<?php

namespace App\Modules\Zone\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Zone\Models\Zone;
use App\Modules\Zone\Repositories\ZoneRepository;
use App\Support\Geo\Polygon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Which zone a place is in — by where it is on the map, not by what somebody
 * picked from a list.
 *
 * Zones used to be names the app offered in a dropdown, so an address was in
 * whichever zone the customer tapped, wherever the pin actually stood. Now the
 * owner draws each zone, and the drawing decides (the owner's rules,
 * 2026-09-29):
 *
 *   - a pin inside a drawn, active zone (in an active city) is in that zone —
 *     whatever the app sent;
 *   - a zone **not drawn yet** keeps working the old way, so an install moves
 *     over one zone at a time and nothing stops the day this ships;
 *   - a pin outside every drawn zone is in no zone, and its order is accepted
 *     without a laundry for an operator to place — exactly as an uncovered
 *     area always was.
 *
 * Laundries and drivers are still matched by `zone_id`, unchanged — which is
 * right by construction once an address's zone is where its pin is: a driver
 * given a zone is only ever offered trips inside it.
 */
class ZoneLocator
{
    public function __construct(private readonly ZoneRepository $zones) {}

    /**
     * The active drawn zone the pin falls inside, or null. Zones may not
     * overlap (refused on save), so there is at most one.
     */
    public function zoneAt(float $lat, float $lng): ?Zone
    {
        foreach ($this->zones->drawnAround($lat, $lng) as $zone) {
            if ($zone->polygon()?->contains($lat, $lng)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * The zone an address is in, and its city.
     *
     * @return array{zone_id: int|null, city_id: int|null}|null null when there
     *                                                          is no pin to go on
     */
    public function forAddress(?float $lat, ?float $lng, mixed $requestedZoneId): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $located = $this->zoneAt($lat, $lng);

        if ($located) {
            return ['zone_id' => $located->id, 'city_id' => $located->city_id];
        }

        // Not inside anything drawn. A zone the owner has not drawn yet is
        // still taken at the app's word; a drawn one the pin is outside of is
        // not — that pin is in no zone.
        $requested = is_numeric($requestedZoneId) ? $this->zones->findActive((int) $requestedZoneId) : null;

        if ($requested && ! $requested->isDrawn()) {
            return ['zone_id' => $requested->id, 'city_id' => $requested->city_id];
        }

        return ['zone_id' => null, 'city_id' => null];
    }

    /**
     * An address's fields as they will be saved: its zone — and the zone's
     * city — decided by the pin. On an edit that moves neither the pin nor the
     * zone, nothing changes.
     *
     * @param  array<string, mixed>  $data  the validated request
     * @return array<string, mixed>
     */
    public function placeAddress(array $data, ?Address $address): array
    {
        if ($address && ! array_key_exists('lat', $data) && ! array_key_exists('lng', $data)
            && ! array_key_exists('zone_id', $data)) {
            return $data;
        }

        $lat = $data['lat'] ?? $address?->lat;
        $lng = $data['lng'] ?? $address?->lng;

        $resolved = $this->forAddress(
            $lat === null ? null : (float) $lat,
            $lng === null ? null : (float) $lng,
            array_key_exists('zone_id', $data) ? $data['zone_id'] : $address?->zone_id,
        );

        if ($resolved === null) {
            return $data;
        }

        $data['zone_id'] = $resolved['zone_id'];

        // The zone's city when there is a zone; otherwise whatever the app
        // sent, or what the address already had — the city is still true of a
        // pin that is in no zone.
        if ($resolved['city_id'] !== null) {
            $data['city_id'] = $resolved['city_id'];
        }

        return $data;
    }

    /**
     * A drawing may not claim ground another zone already has.
     *
     * One check for the form and for the save: the form's gives the owner the
     * message beside the map; the save's runs again inside its transaction
     * with the neighbours locked, so two drawings saved at the same moment
     * cannot both pass against the other's old shape.
     *
     * @throws ValidationException naming the zone it overlaps
     */
    public function assertNoOverlap(Polygon $polygon, ?int $zoneId, bool $lock = false): void
    {
        foreach ($this->zones->drawnExcept($zoneId, $polygon->boundingBox(), $lock) as $other) {
            if ($other->polygon()?->overlaps($polygon)) {
                throw ValidationException::withMessages([
                    'boundary' => __('The drawing overlaps «:zone». Draw up to its edge, not over it.', [
                        'zone' => getLocalizedValueDashboard($other, 'name'),
                    ]),
                ]);
            }
        }
    }

    /**
     * After a zone's drawing changed, or a drawn zone was switched on: move the
     * addresses it now claims, and let go of the ones it no longer covers.
     *
     * Nothing else is touched — an address in some other zone moves only if a
     * drawn, active zone now claims its pin; an address in a zone that is
     * switched off keeps it, as switching off has always meant. An address
     * that would be left in no zone while an order on it is still under way
     * keeps its zone until the order is done: its legs are being routed by it,
     * and a leg with no zone can be given to nobody.
     *
     * Through the models, one by one, so each move is in the activity log; the
     * zones that may claim anything are read once, not once per address.
     *
     * @return array{moved: int, held: int}
     */
    public function relocateAround(Zone $zone, ?Polygon $before): array
    {
        $boxes = array_values(array_filter([$before?->boundingBox(), $zone->polygon()?->boundingBox()]));

        /** @var Collection<int, array{zone: Zone, polygon: Polygon}> $claimers */
        $claimers = $this->zones->claimingWithin($boxes)
            ->map(fn (Zone $candidate) => ['zone' => $candidate, 'polygon' => $candidate->polygon()])
            ->filter(fn (array $candidate) => $candidate['polygon'] !== null)
            ->values();

        $moved = 0;
        $held = 0;

        foreach ($this->zones->addressesNear($zone->id, $boxes) as $address) {
            $lat = (float) $address->lat;
            $lng = (float) $address->lng;

            $claim = $claimers->first(fn (array $candidate) => $candidate['polygon']->contains($lat, $lng));

            if ($claim) {
                [$target, $city] = [$claim['zone']->id, $claim['zone']->city_id];
            } elseif ($address->zone_id === $zone->id && $zone->isDrawn() && ! $zone->polygon()?->contains($lat, $lng)) {
                // This zone's own address, outside its new drawing.
                [$target, $city] = [null, $address->city_id];
            } else {
                continue;
            }

            if ($target === $address->zone_id) {
                continue;
            }

            if ($target === null && $this->zones->hasOrderInProgress($address)) {
                $held++;

                continue;
            }

            $address->update(['zone_id' => $target, 'city_id' => $city]);
            $moved++;
        }

        return ['moved' => $moved, 'held' => $held];
    }
}
