<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one way the application asks how far apart two things are.
 *
 * Wraps whichever `Router` is configured and adds the two behaviours every call
 * site would otherwise have to repeat:
 *
 * - **A leg always comes back.** A provider returns null for what it could not
 *   measure; this substitutes a straight line and stamps it as an estimate. No
 *   caller has to decide what to do about a vendor being down, because by the
 *   time they see the answer it has already been decided.
 * - **Nothing is measured twice.** Providers bill per element, and a customer
 *   who opens the quote screen three times before submitting would otherwise be
 *   three lookups. Results are cached per origin/destination pair, snapped to a
 *   grid so two customers in one building share one entry.
 *
 * **A fallback is never cached.** It is the single most important line in this
 * class: caching a straight line for a day would turn a thirty-second provider
 * outage into a day of wrong distances, and — because the fee now measures the
 * road — a day of undercharged deliveries that nothing would flag.
 */
class RoutingService
{
    public function __construct(private readonly HaversineRouter $haversine) {}

    /**
     * Measure one origin against many destinations.
     *
     * @param  array<array-key, Coordinate>  $destinations
     * @return array<array-key, RouteLeg> keyed exactly as `$destinations`
     */
    public function matrix(Coordinate $origin, array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        $router = $this->router();

        // Nothing to cache and nothing to call: the straight line is arithmetic.
        if ($router instanceof HaversineRouter) {
            return $router->matrix($origin, $destinations);
        }

        $precision = (int) config('routing.cache_precision', 4);
        $out = [];
        $misses = [];

        foreach ($destinations as $key => $destination) {
            $cached = Cache::get($this->cacheKey($router->source(), $origin, $destination, $precision));
            $leg = is_array($cached) ? RouteLeg::fromArray($cached) : null;

            if ($leg !== null) {
                $out[$key] = $leg;

                continue;
            }

            $misses[$key] = $destination;
        }

        if ($misses !== []) {
            $fresh = $router->matrix($origin, $misses);

            foreach ($misses as $key => $destination) {
                $leg = $fresh[$key] ?? null;

                if ($leg instanceof RouteLeg) {
                    Cache::put(
                        $this->cacheKey($router->source(), $origin, $destination, $precision),
                        $leg->toArray(),
                        (int) config('routing.cache_ttl', 86400),
                    );

                    $out[$key] = $leg;

                    continue;
                }

                // Unmeasured. Estimate it, and deliberately do not cache the
                // estimate — the next call must ask the provider again.
                $out[$key] = RouteLeg::straightLine($this->haversine->km($origin, $destination));
            }
        }

        // Rebuilt from the input rather than returned as accumulated: the caller's
        // key order is preserved, and a key that somehow produced no leg gets an
        // estimate here instead of leaving a Coordinate in a RouteLeg array.
        $ordered = [];

        foreach ($destinations as $key => $destination) {
            $ordered[$key] = $out[$key] ?? RouteLeg::straightLine($this->haversine->km($origin, $destination));
        }

        return $ordered;
    }

    /**
     * Measure a single pair.
     */
    public function between(Coordinate $from, Coordinate $to): RouteLeg
    {
        return $this->matrix($from, ['leg' => $to])['leg'];
    }

    /**
     * Straight-line distance, whatever the configured provider is.
     *
     * For the places that genuinely want the local figure — a tolerance compared
     * against itself, a test — rather than a road measurement.
     */
    public function straightLineKm(Coordinate $from, Coordinate $to): float
    {
        return $this->haversine->km($from, $to);
    }

    /**
     * The configured provider, resolved per call so a config change inside a
     * test takes effect without rebuilding the container.
     */
    private function router(): Router
    {
        $name = (string) config('routing.driver', 'haversine');
        $class = config('routing.drivers.'.$name);

        if (! is_string($class) || ! class_exists($class)) {
            Log::warning('[Routing] Unknown routing driver, using straight-line distance', ['driver' => $name]);

            return $this->haversine;
        }

        return app($class);
    }

    private function cacheKey(string $source, Coordinate $origin, Coordinate $destination, int $precision): string
    {
        return 'route:'.$source.':'.$origin->grid($precision).':'.$destination->grid($precision);
    }
}
