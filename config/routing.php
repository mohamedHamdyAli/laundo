<?php

use App\Services\Routing\GoogleDistanceMatrixRouter;
use App\Services\Routing\HaversineRouter;

return [

    /*
    |--------------------------------------------------------------------------
    | Routing Driver
    |--------------------------------------------------------------------------
    |
    | `haversine` measures the straight line locally and calls nobody. It is what
    | the whole codebase used before road distance existed, it needs no key, and
    | it is what every other driver falls back to.
    |
    | `google` uses the Distance Matrix API. It needs a key — normally the
    | `Google_Maps_Key` settings row, which the general settings screen writes,
    | so it can be rotated from the panel without a deploy. Without one it logs
    | once and behaves exactly like `haversine`.
    |
    | The test suite pins this to `haversine` in phpunit.xml: the suite runs
    | offline, and a fee that depended on a live third party would fail on a
    | train.
    |
    */
    'driver' => env('ROUTING_DRIVER', 'google'),

    'drivers' => [
        'haversine' => HaversineRouter::class,
        'google' => GoogleDistanceMatrixRouter::class,
    ],

    'google' => [
        /*
         * Normally null, and normally it should stay that way — the key lives in
         * the `Google_Maps_Key` settings row so it can be changed by whoever is
         * awake when it leaks.
         *
         * Set it here (via GOOGLE_MAPS_KEY) only on an install that would rather
         * the secret never entered a database backup. When it is set it *wins*,
         * and the dashboard field does nothing.
         */
        'key' => env('GOOGLE_MAPS_KEY'),

        /*
         * The Routes API, not the legacy Distance Matrix endpoint. A Google
         * Cloud project created recently cannot enable the legacy one at all —
         * it answers REQUEST_DENIED with «You're calling a legacy API» however
         * valid the key is.
         */
        'endpoint' => env(
            'GOOGLE_ROUTES_ENDPOINT',
            'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix',
        ),

        /*
         * Elements per request. The traffic-unaware tier allows 625; this is
         * well under it and keeps one failed request from costing a whole zone
         * its measurements.
         */
        'max_destinations' => 100,
    ],

    /*
     * Short on purpose. This runs inside order placement, and a provider that
     * has stopped answering must cost the customer a moment, not a minute — the
     * straight-line fallback is right there.
     */
    'timeout' => (int) env('ROUTING_TIMEOUT', 5),

    /*
     * A day. Roads do not move, and no traffic-aware figure is requested
     * precisely so that the answer is stable enough to keep this long.
     */
    'cache_ttl' => (int) env('ROUTING_CACHE_TTL', 86400),

    /*
     * Decimal places the cache key snaps coordinates to. Four is about an
     * eleven-metre square: finer than the pin a customer drops, coarse enough
     * that one building is one billed lookup.
     */
    'cache_precision' => (int) env('ROUTING_CACHE_PRECISION', 4),

];
