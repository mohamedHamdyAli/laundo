<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road distance and driving time from Google's **Routes API**
 * (`distanceMatrix/v2:computeRouteMatrix`).
 *
 * Not the old `maps.googleapis.com/maps/api/distancematrix` endpoint, and that
 * is not a preference. A Google Cloud project created recently cannot enable
 * the legacy Distance Matrix API at all — it answers every call with
 * `REQUEST_DENIED` and «You're calling a legacy API, which is not enabled for
 * your project». The key is fine; the API is simply gone for new projects. If
 * you are reading this while porting to some other provider, that is the trap.
 *
 * **It never throws.** Every failure path — no key, a timeout, a refused
 * request, an exhausted quota, a destination Google cannot reach — returns null
 * for the legs it could not measure, and `RoutingService` substitutes a straight
 * line. Placing an order must not depend on a third party being up.
 *
 * Three things about this API that cost time if you learn them from production:
 *
 * - **The response is unordered.** It is a flat JSON array of elements carrying
 *   their own `originIndex` and `destinationIndex`, and Google returns them in
 *   whatever order it finished them — asking for two destinations comes back
 *   with the second one first often enough to pass a one-destination test and
 *   fail in the zone that has three laundries. Results are matched on
 *   `destinationIndex`, never on position.
 * - **`duration` is a string**, `"505s"`, not a number of seconds.
 * - **A per-element failure is not an HTTP failure.** `condition` is
 *   `ROUTE_EXISTS` or `ROUTE_NOT_FOUND`, and `status` carries a code when that
 *   one pair failed while the request as a whole succeeded.
 *
 * `routingPreference` is `TRAFFIC_UNAWARE` on purpose. The traffic-aware modes
 * return a different number every hour — and results are cached for a day, so
 * we would be caching rush hour and serving it at midnight. The traffic-free
 * duration is stable, which is what a cached figure has to be. It is also the
 * cheaper tier and allows far more elements per request.
 */
class GoogleDistanceMatrixRouter implements Router
{
    /**
     * Whether this process has already complained about the missing key. One
     * warning is a configuration error worth seeing; one per order is noise that
     * buries everything else in the log.
     */
    private static bool $warnedAboutKey = false;

    /**
     * @param  array<array-key, Coordinate>  $destinations
     * @return array<array-key, RouteLeg|null>
     */
    public function matrix(Coordinate $origin, array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        $key = $this->apiKey();

        if ($key === null) {
            if (! self::$warnedAboutKey) {
                self::$warnedAboutKey = true;
                Log::warning('[Routing] No Google Maps key configured — falling back to straight-line distance. Set it on the general settings screen.');
            }

            return $this->allUnmeasured($destinations);
        }

        $out = $this->allUnmeasured($destinations);

        $chunks = array_chunk($destinations, (int) config('routing.google.max_destinations', 100), true);

        foreach ($chunks as $chunk) {
            foreach ($this->fetch($origin, $chunk, $key) as $chunkKey => $leg) {
                $out[$chunkKey] = $leg;
            }
        }

        return $out;
    }

    public function source(): string
    {
        return RouteLeg::SOURCE_GOOGLE;
    }

    /**
     * One request, one chunk.
     *
     * @param  array<array-key, Coordinate>  $destinations
     * @return array<array-key, RouteLeg|null>
     */
    private function fetch(Coordinate $origin, array $destinations, string $key): array
    {
        // The caller's keys, in the order we send them. Google answers with the
        // index it was given, so this is how a laundry id survives the round
        // trip through an API that only speaks in positions.
        $keys = array_keys($destinations);

        try {
            $response = Http::timeout((int) config('routing.timeout', 5))
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    // Mandatory. The Routes API refuses a request with no field
                    // mask rather than returning everything, and omitting
                    // `status` and `condition` means a failed element arrives
                    // looking like a zero-distance one.
                    'X-Goog-FieldMask' => 'originIndex,destinationIndex,duration,distanceMeters,status,condition',
                ])
                ->post((string) config('routing.google.endpoint'), [
                    'origins' => [$this->waypoint($origin)],
                    'destinations' => array_map(fn (Coordinate $c) => $this->waypoint($c), array_values($destinations)),
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                ]);
        } catch (Throwable $e) {
            // A connection error, a DNS failure, a timeout. Deliberately caught
            // rather than left to bubble: the caller is inside order placement.
            Log::warning('[Routing] Routes API request failed', ['error' => $e->getMessage()]);

            return $this->allUnmeasured($destinations);
        }

        if (! $response->successful()) {
            Log::warning('[Routing] Routes API returned an error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return $this->allUnmeasured($destinations);
        }

        $elements = $response->json();

        if (! is_array($elements)) {
            Log::warning('[Routing] Routes API returned an unreadable body');

            return $this->allUnmeasured($destinations);
        }

        $out = $this->allUnmeasured($destinations);

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            $index = $element['destinationIndex'] ?? null;

            // Matched on the index Google reports, never on the position in the
            // array — see the class docblock.
            if (! is_int($index) || ! array_key_exists($index, $keys)) {
                continue;
            }

            $out[$keys[$index]] = $this->legFrom($element);
        }

        return $out;
    }

    /**
     * One element of the matrix, or null when Google could not route it.
     *
     * @param  array<string, mixed>  $element
     */
    private function legFrom(array $element): ?RouteLeg
    {
        // `status` is an empty object on success and carries a code when that
        // pair failed on its own.
        if (! empty($element['status'])) {
            return null;
        }

        if (($element['condition'] ?? null) !== 'ROUTE_EXISTS') {
            return null;
        }

        $metres = $element['distanceMeters'] ?? null;

        if (! is_numeric($metres)) {
            return null;
        }

        return RouteLeg::road(
            (float) $metres / 1000,
            $this->minutesFrom($element['duration'] ?? null),
        );
    }

    /**
     * `"505s"` into 8.4 minutes.
     */
    private function minutesFrom(mixed $duration): ?float
    {
        if (! is_string($duration) || ! preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $m)) {
            return null;
        }

        return ((float) $m[1]) / 60;
    }

    /**
     * @return array<string, mixed>
     */
    private function waypoint(Coordinate $point): array
    {
        return [
            'waypoint' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $point->lat,
                        'longitude' => $point->lng,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<array-key, Coordinate>  $destinations
     * @return array<array-key, null>
     */
    private function allUnmeasured(array $destinations): array
    {
        return array_map(fn () => null, $destinations);
    }

    /**
     * The key, from the config when an install would rather keep it in a file,
     * otherwise from the settings row the dashboard writes.
     *
     * The settings row is the normal home, and the reason is operational: a key
     * that leaks is rotated from the panel in a minute, where a key in a config
     * file needs somebody with SSH and a deploy. The config wins when it is set,
     * so an install that would rather the secret never entered a database backup
     * can have that, and the dashboard field then simply does nothing.
     *
     * The settings read is a `rememberForever` cache hit that the update screen
     * invalidates — about a millisecond, against a network call of two hundred.
     */
    private function apiKey(): ?string
    {
        $fromConfig = config('routing.google.key');

        if (filled($fromConfig)) {
            return (string) $fromConfig;
        }

        $fromSettings = getSettingValue('Google_Maps_Key');

        return filled($fromSettings) ? (string) $fromSettings : null;
    }
}
