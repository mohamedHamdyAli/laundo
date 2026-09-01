<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;

/**
 * Works out the delivery fee.
 *
 * The rule, as decided: **distance from the laundry to the customer's address,
 * multiplied by the pickup zone's per-kilometre rate**, floored at the zone's
 * minimum, and multiplied by 1.5 when pickup and delivery are different
 * addresses — a driver covering two locations does more work than one.
 *
 * Returns a result object rather than a bare number because the fee can
 * legitimately be *unknown*: an unassigned laundry has no location to measure
 * from, and a zone may not have been priced yet. Returning 0.00 in those cases
 * would quietly tell a customer delivery is free.
 */
class DeliveryFeeCalculator
{
    /**
     * Multiplier when the clothes are collected from one address and returned to
     * another.
     */
    public const DIFFERENT_ADDRESS_MULTIPLIER = 1.5;

    /**
     * Earth's mean radius in kilometres, for the haversine below.
     */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * @return array{fee: float|null, distance_km: float|null, reason: string|null}
     */
    public function calculate(?Laundry $laundry, Address $pickup, ?Address $delivery = null): array
    {
        $delivery ??= $pickup;

        if (! $laundry) {
            // Accepted by decision: an order with no covering laundry still gets
            // taken, and operations assign it. The fee follows once it has one.
            return $this->unknown('no_laundry_assigned');
        }

        if ($laundry->lat === null || $laundry->lng === null) {
            return $this->unknown('laundry_has_no_coordinates');
        }

        $zone = $pickup->zone;

        if (! $zone || $zone->price_per_km === null) {
            return $this->unknown('zone_has_no_rate');
        }

        $distance = $this->distanceKm(
            (float) $laundry->lat,
            (float) $laundry->lng,
            (float) $pickup->lat,
            (float) $pickup->lng,
        );

        $fee = $distance * (float) $zone->price_per_km;

        // Distance alone is absurd at short range: a 300-metre trip should not
        // cost a fraction of a pound.
        if ($zone->min_delivery_fee !== null) {
            $fee = max($fee, (float) $zone->min_delivery_fee);
        }

        if ($delivery->id !== $pickup->id) {
            $fee *= self::DIFFERENT_ADDRESS_MULTIPLIER;
        }

        return [
            'fee' => round($fee, 2),
            'distance_km' => round($distance, 2),
            'reason' => null,
        ];
    }

    /**
     * Great-circle distance between two points.
     *
     * Haversine rather than a routing service: it needs no network call and no
     * API key, and for pricing a short urban delivery the straight-line figure is
     * the honest approximation. Swapping in real road distance later only changes
     * this method.
     */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array{fee: null, distance_km: null, reason: string}
     */
    private function unknown(string $reason): array
    {
        return ['fee' => null, 'distance_km' => null, 'reason' => $reason];
    }
}
