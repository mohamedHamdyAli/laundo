<?php

use App\Services\Push\FcmPushDriver;
use App\Services\Push\LogPushDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Push Driver
    |--------------------------------------------------------------------------
    |
    | `log` writes notifications to the application log and sends nothing. It is
    | the development default, and it stays the default until a Firebase service
    | account exists — a driver that cannot authenticate would fail on every send
    | and fill the log with noise rather than notifying anybody.
    |
    | To go live: put the service-account JSON somewhere the app can read, point
    | FCM_CREDENTIALS at it, and set PUSH_DRIVER=fcm. Nothing else changes.
    |
    */
    'driver' => env('PUSH_DRIVER', 'log'),

    'drivers' => [
        'log' => LogPushDriver::class,
        'fcm' => FcmPushDriver::class,
    ],

    'fcm' => [
        // The service-account JSON downloaded from the Firebase console.
        // Deliberately a path rather than inline credentials: a private key in an
        // env var ends up in every log line that dumps the environment.
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/firebase.json')),
    ],

    'log_channel' => env('PUSH_LOG_CHANNEL', config('logging.default')),

    // Short on purpose. A notification is not worth holding a web request open
    // for, and the dispatcher treats a timeout as an ordinary failure.
    'timeout' => (int) env('PUSH_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Per-subject hourly cap
    |--------------------------------------------------------------------------
    |
    | The most non-transactional notifications one person may receive about one
    | thing in an hour. An order that moves three stages in a minute used to send
    | three, and nothing rate-limited anything.
    |
    | Transactional events ignore this entirely — the final-price notification
    | stalls the order when it is missed, so it is never a candidate for silence.
    |
    | Set to 0 to disable the cap.
    |
    */
    'rate_limit_per_hour' => (int) env('PUSH_RATE_LIMIT_PER_HOUR', 3),

];
