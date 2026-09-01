<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the application log instead of sending it.
 *
 * The development and testing driver: the full OTP flow is exercisable end to
 * end without a vendor account, and the code is readable in `storage/logs`.
 *
 * Deliberately loud about what it is, so a misconfigured production deploy is
 * obvious in the logs rather than silently swallowing every OTP.
 */
class LogSmsDriver implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        Log::channel(config('sms.log_channel'))->info('[SMS:LOG-DRIVER — NOT ACTUALLY SENT]', [
            'to' => $phone,
            'message' => $message,
        ]);

        return true;
    }
}
