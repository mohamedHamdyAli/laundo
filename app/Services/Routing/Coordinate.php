<?php

namespace App\Services\Routing;

/**
 * A point on the map, and the only thing a router is allowed to be given.
 *
 * A laundry, an address and a driver each carry a nullable `lat`/`lng` pair, and
 * every call site used to re-test both halves before measuring anything. Making
 * the pair a type moves that test to one place: `from()` returns null when the
 * thing cannot be located, so a router never receives half a point.
 */
final class Coordinate
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {}

    /**
     * The point, or null when either half is missing.
     */
    public static function from(float|string|null $lat, float|string|null $lng): ?self
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return null;
        }

        return new self((float) $lat, (float) $lng);
    }

    /**
     * `lat,lng` as Google's Distance Matrix wants it.
     */
    public function pair(): string
    {
        return $this->lat.','.$this->lng;
    }

    /**
     * The same point snapped to a grid, for cache keys.
     *
     * Two customers in one building must not each be billed a lookup. Four
     * decimal places is roughly an eleven-metre square, which is finer than the
     * accuracy of the pin a customer drops and far coarser than the float noise
     * a `decimal(10,7)` column round-trips through.
     */
    public function grid(int $precision): string
    {
        return number_format($this->lat, $precision, '.', '')
            .','.number_format($this->lng, $precision, '.', '');
    }
}
