<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * Writes the notification to the log instead of sending it.
 *
 * The development and testing driver, exactly like LogSmsDriver: the whole
 * notification path is exercisable without a Firebase project, and what would
 * have been sent is readable in `storage/logs`.
 *
 * Loud about what it is, so a production deploy that never got its credentials is
 * obvious in the logs rather than silently swallowing every notification.
 */
class LogPushDriver implements PushSender
{
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        Log::channel(config('push.log_channel'))->info('[PUSH:LOG-DRIVER — NOT ACTUALLY SENT]', [
            'token' => substr($token, 0, 12).'…',
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        return true;
    }

    public function lastFailureWasPermanent(): bool
    {
        return false;
    }
}
