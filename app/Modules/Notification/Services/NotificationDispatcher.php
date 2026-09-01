<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Models\DeviceToken;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\Notification\Models\NotificationPreference;
use App\Modules\User\Models\User;
use App\Notifications\AdminNotification;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides who hears what, and writes down what happened.
 *
 * Three rules, and everything else is plumbing.
 *
 *  1. **A failed notification never breaks the thing that triggered it.** Every
 *     channel is wrapped: a Firebase outage must not roll back a delivery a
 *     driver has already made. The failure is logged and the business action
 *     stands.
 *
 *  2. **A muted user still gets transactional messages.** The design's
 *     «الإشعارات» toggle silences noise. It cannot silence «السعر النهائي جاهز»,
 *     because an order nobody confirms is an order that stops, and the customer
 *     would never learn why.
 *
 *  3. **Everything is logged, including the skips.** "I never got it" is
 *     otherwise unanswerable, and a token that has been dead for a month is
 *     invisible until somebody can count its failures.
 */
class NotificationDispatcher
{
    public function __construct(private readonly PushSender $push) {}

    /**
     * Tell one person one thing, on every channel that applies.
     *
     * @return array<string, bool> channel => delivered
     */
    public function send(User $user, NotificationMessage $message): array
    {
        $results = [];

        // Checked before the channel loop, because the cap limits the *message*
        // and not each delivery route. An order that moves three stages in a
        // minute used to send three notifications; nothing rate-limited anything,
        // which is how a useful notification becomes one people mute.
        if ($this->overRateLimit($user, $message)) {
            foreach ($message->event->channels() as $channel) {
                $this->log($user, $message, $channel, NotificationLog::SKIPPED, null, 'rate limited for this subject');
                $results[$channel] = false;
            }

            return $results;
        }

        foreach ($message->event->channels() as $channel) {
            if (! $this->allows($user, $channel, $message)) {
                $this->log($user, $message, $channel, NotificationLog::SKIPPED, null, 'muted by the user');
                $results[$channel] = false;

                continue;
            }

            $results[$channel] = match ($channel) {
                'database' => $this->toDatabase($user, $message),
                'push' => $this->toPush($user, $message),
                default => false,
            };
        }

        return $results;
    }

    /**
     * Tell several people the same thing.
     *
     * @param  iterable<User>  $users
     */
    public function sendMany(iterable $users, NotificationMessage $message): int
    {
        $count = 0;

        foreach ($users as $user) {
            $this->send($user, $message);
            $count++;
        }

        return $count;
    }

    /**
     * Whether this person has already had enough messages about this thing.
     *
     * Three rules, and each of them is the reason the cap is safe to have:
     *
     *   1. **Transactional events are never capped.** «السعر النهائي جاهز» stalls
     *      the order when it is missed, and a customer whose third notification of
     *      the hour is the one that matters must still receive it.
     *   2. **Scoped to the subject**, not to the user. Two different orders moving
     *      at once are two conversations, and silencing one because the other is
     *      busy would be worse than the noise.
     *   3. **A message with no subject is never capped.** Nothing to group it by,
     *      and guessing would silence unrelated things together.
     *
     * The log is the counter. It already records the subject of every message, so
     * a separate tally would be a second source of the same truth.
     */
    private function overRateLimit(User $user, NotificationMessage $message): bool
    {
        if ($message->event->isTransactional()) {
            return false;
        }

        if ($message->subject === null) {
            return false;
        }

        $limit = (int) config('push.rate_limit_per_hour', 3);

        if ($limit <= 0) {
            return false;
        }

        // Counted on the `database` channel alone. Every message writes exactly one
        // database row, so this counts *messages*; counting all rows would halve
        // the effective limit for a two-channel event, and counting distinct
        // events would let the same event through repeatedly.
        $sent = NotificationLog::where('user_id', $user->id)
            ->where('subject_type', $message->subject::class)
            ->where('subject_id', $message->subject->getKey())
            ->where('channel', 'database')
            ->where('status', NotificationLog::SENT)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return $sent >= $limit;
    }

    /**
     * Whether this channel is open for this user.
     *
     * Absent preference means enabled — only exceptions are stored.
     */
    public function allows(User $user, string $channel, NotificationMessage $message): bool
    {
        if ($message->event->isTransactional()) {
            // Not negotiable: silence here stalls the order.
            return true;
        }

        $preference = NotificationPreference::where('user_id', $user->id)
            ->where('channel', $channel)
            ->first();

        return $preference === null || $preference->enabled;
    }

    /**
     * The in-app list. Always the record, even when push fails.
     */
    private function toDatabase(User $user, NotificationMessage $message): bool
    {
        try {
            $user->notify(new AdminNotification(
                $message->title,
                $message->body,
                $message->url,
                ['event' => $message->event->value] + $message->data,
            ));

            $this->log($user, $message, 'database', NotificationLog::SENT);

            return true;
        } catch (Throwable $e) {
            $this->log($user, $message, 'database', NotificationLog::FAILED, null, $e->getMessage());

            return false;
        }
    }

    /**
     * Every handset this person has registered.
     *
     * A user with no tokens is not a failure — they have simply never opened the
     * app on a device that asked for permission — so it is logged as a skip.
     */
    private function toPush(User $user, NotificationMessage $message): bool
    {
        $tokens = DeviceToken::where('user_id', $user->id)->get();

        if ($tokens->isEmpty()) {
            $this->log($user, $message, 'push', NotificationLog::SKIPPED, null, 'no registered device');

            return false;
        }

        $anyDelivered = false;

        foreach ($tokens as $device) {
            try {
                $sent = $this->push->send(
                    $device->token,
                    $message->title,
                    $message->body,
                    ['event' => $message->event->value] + $message->data,
                );
            } catch (Throwable $e) {
                // A vendor outage is not this order's problem.
                Log::warning('[notifications] push threw', ['error' => $e->getMessage()]);
                $sent = false;
            }

            if ($sent) {
                $device->update(['last_used_at' => now()]);
                $anyDelivered = true;

                $this->log($user, $message, 'push', NotificationLog::SENT, $this->mask($device->token));

                continue;
            }

            $this->log($user, $message, 'push', NotificationLog::FAILED, $this->mask($device->token),
                $this->push->lastFailureWasPermanent() ? 'token rejected permanently' : 'send failed');

            // A token FCM has invalidated fails forever. Keeping it guarantees a
            // failure on every future send; deleting one that merely timed out
            // would silence a working handset.
            if ($this->push->lastFailureWasPermanent()) {
                $device->delete();
            }
        }

        return $anyDelivered;
    }

    private function log(
        User $user,
        NotificationMessage $message,
        string $channel,
        string $status,
        ?string $destination = null,
        ?string $failure = null,
    ): void {
        NotificationLog::create([
            'user_id' => $user->id,
            'event' => $message->event,
            'channel' => $channel,
            'status' => $status,
            'destination' => $destination,
            'title' => $message->title,
            'body' => $message->body,
            'failure_reason' => $failure,
            'subject_type' => $message->subject ? $message->subject::class : null,
            'subject_id' => $message->subject?->getKey(),
        ]);
    }

    /**
     * A device token is a credential. Logging it whole would put it in every
     * backup of the log table.
     */
    private function mask(string $token): string
    {
        return substr($token, 0, 12).'…';
    }
}
