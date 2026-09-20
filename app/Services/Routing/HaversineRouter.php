<?php

namespace App\Services\Routing;

/**
 * Straight-line distance, computed locally.
 *
 * Two jobs, and it is worth being clear they are different. It is a **provider**
 * in its own right, for an install with no maps account — set
 * `ROUTING_DRIVER=haversine` and nothing calls anybody. And it is the
 * **fallback** every other provider falls back to, which is why it can never
 * fail, never reach the network and never need a key.
 *
 * It answers distance and refuses to answer duration. A minute figure derived
 * from a straight line and a guessed speed is not a measurement, and an operator
 * reading «١٢ دقيقة» beside a laundry has no way to tell it was invented.
 */
class HaversineRouter implements Router
{
    /**
     * Earth's mean radius in kilometres.
     */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * @param  array<array-key, Coordinate>  $destinations
     * @return array<array-key, RouteLeg>
     */
    public function matrix(Coordinate $origin, array $destinations): array
    {
        $out = [];

        foreach ($destinations as $key => $destination) {
            $out[$key] = RouteLeg::straightLine($this->km($origin, $destination));
        }

        return $out;
    }

    public function source(): string
    {
        return RouteLeg::SOURCE_HAVERSINE;
    }

    /**
     * Great-circle distance between two points.
     */
    public function km(Coordinate $from, Coordinate $to): float
    {
        $dLat = deg2rad($to->lat - $from->lat);
        $dLng = deg2rad($to->lng - $from->lng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($from->lat)) * cos(deg2rad($to->lat)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
