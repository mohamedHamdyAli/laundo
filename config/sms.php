<?php

use App\Services\Sms\LogSmsDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | SMS Driver
    |--------------------------------------------------------------------------
    |
    | `log` writes messages to the application log and sends nothing. It is the
    | development default and the only driver implemented so far.
    |
    | To add a real provider: implement App\Services\Sms\SmsSender, register it
    | in the `drivers` map below, and point SMS_DRIVER at it. Nothing else in the
    | application needs to change.
    |
    */
    'driver' => env('SMS_DRIVER', 'log'),

    'drivers' => [
        'log' => LogSmsDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | Which logging channel the `log` driver writes to.
    |
    */
    'log_channel' => env('SMS_LOG_CHANNEL', config('logging.default')),

    /*
    |--------------------------------------------------------------------------
    | OTP
    |--------------------------------------------------------------------------
    |
    | `length` is 6 to match the design's six-box input. `ttl_seconds` is 120,
    | just past the 01:59 countdown the design shows, so a code does not expire
    | while the timer still reads a second.
    |
    | `max_attempts` is the important one: a six-digit code is a million
    | combinations, which an unthrottled verify endpoint gives up in minutes.
    |
    */
    'otp' => [
        'length' => (int) env('OTP_LENGTH', 6),
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 120),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    ],
];
