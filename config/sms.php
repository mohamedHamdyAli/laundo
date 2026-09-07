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

        /*
         * A fixed code, in place of a random one, for as long as SMS delivery is
         * not integrated. No provider is implemented yet — `log` is the only
         * driver — so nobody can receive a code they did not generate
         * themselves, and a random code would simply make the apps untestable.
         *
         * Everything else about the code still holds while this is set: it is
         * hashed at rest, it expires, it is single-use, and wrong guesses are
         * counted. Only the value is predictable.
         *
         * Switching it off when the provider lands is one line — set
         * `OTP_STATIC_CODE=` in .env, or drop this default — and the flow
         * returns to random codes with no other change. Anything that is not
         * exactly `length` digits is ignored rather than honoured, because a
         * typo'd value would be a code the `digits:6` request rules refuse,
         * leaving accounts that could never be verified.
         */
        'static_code' => env('OTP_STATIC_CODE', '123456'),
    ],

    /*
     * The ticket handed out when a reset code checks out, and spent by the
     * password step. Its own lifetime, deliberately longer than the OTP's two
     * minutes: the code has to be read off an SMS and typed against a visible
     * countdown, while this one only has to survive somebody choosing and
     * confirming a password on the next screen.
     */
    'password_reset_token' => [
        'ttl_seconds' => (int) env('PASSWORD_RESET_TOKEN_TTL_SECONDS', 600),
    ],
];
