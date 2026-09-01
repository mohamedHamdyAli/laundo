<?php

namespace App\Modules\Order\Services;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\LaundryService\Models\LaundryService;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Service\Models\Service;

/**
 * Picks the laundry for an order.
 *
 * A candidate must satisfy three conditions, all of them declared by the tenant
 * itself in P2: it is active, it has claimed the pickup address's zone, and it
 * offers the requested service. Among those that qualify, the nearest one wins —
 * short trips cost the customer less and the driver less time.
 *
 * When nothing qualifies the answer is **null, and that is not a failure**. By
 * decision the order is still accepted, sits unassigned, and operations place it.
 * Refusing at the door would throw away a customer over a gap in our own
 * coverage data.
 *
 * Global scopes are dropped throughout: this runs while a *customer* is
 * authenticated, and a customer is not a tenant, so the scopes would be
 * inactive anyway — but a super admin placing an order on someone's behalf must
 * not silently narrow the search either.
 */
class LaundryAssigner
{
    public function __construct(private readonly DeliveryFeeCalculator $distance) {}

    /**
     * The best laundry for this pickup and service, or null when none covers it.
     */
    public function assign(Address $pickup, Service $service): ?Laundry
    {
        $candidates = $this->candidates($pickup, $service);

        if ($candidates === []) {
            return null;
        }

        // Nearest first. A laundry without coordinates cannot be measured, so it
        // sorts last rather than being excluded — it is still a legitimate
        // candidate, just an unrankable one.
        usort($candidates, fn (Laundry $a, Laundry $b) => $this->rank($a, $pickup) <=> $this->rank($b, $pickup));

        return $candidates[0];
    }

    /**
     * Every laundry that could take this order.
     *
     * @return array<int, Laundry>
     */
    public function candidates(Address $pickup, Service $service): array
    {
        if ($pickup->zone_id === null) {
            // No zone means no coverage claim can match it. The order is accepted
            // unassigned; operations will place it.
            return [];
        }

        $inZone = LaundryZone::withoutGlobalScopes()
            ->where('zone_id', $pickup->zone_id)
            ->pluck('laundry_id');

        if ($inZone->isEmpty()) {
            return [];
        }

        $offering = LaundryService::withoutGlobalScopes()
            ->where('service_id', $service->id)
            ->where('status', 'active')
            ->whereIn('laundry_id', $inZone)
            ->pluck('laundry_id');

        if ($offering->isEmpty()) {
            return [];
        }

        return Laundry::withoutGlobalScopes()
            ->whereIn('id', $offering)
            ->where('status', 'active')
            ->get()
            ->all();
    }

    /**
     * Sort key: distance in kilometres, or PHP_INT_MAX for a laundry we cannot
     * locate.
     */
    private function rank(Laundry $laundry, Address $pickup): float
    {
        if (! $laundry->hasCoordinates()) {
            return PHP_FLOAT_MAX;
        }

        return $this->distance->distanceKm(
            (float) $laundry->lat,
            (float) $laundry->lng,
            (float) $pickup->lat,
            (float) $pickup->lng,
        );
    }
}
