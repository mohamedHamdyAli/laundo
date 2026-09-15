<?php

namespace App\Jobs;

use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\User\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One hand-written notification, for one person, off the request.
 *
 * **The first queued job in this application**, and the reason the rest of it is
 * not: everything here is synchronous by decision, because a worker that dies is
 * invisible and a business action that silently did not happen is worse than a
 * slow one. That reasoning still holds for an order moving or a payment landing.
 * It does not hold for an announcement to three thousand customers, which cannot
 * be done inside an HTTP request at all — each recipient is an FCM round-trip,
 * and the alternative to queueing it was a cap.
 *
 * **One job per recipient, not one per chunk.** A chunk that fails at the
 * sixtieth of a hundred would, on retry, send to the first fifty-nine a second
 * time — and a duplicate notification to a customer cannot be taken back. One
 * recipient per job makes a retry exact: it reaches the one person whose send
 * actually threw. The `jobs` table carries a row each, which the database driver
 * handles fine and which is deleted as it is worked.
 *
 * Nothing about *how* a message is delivered lives here. It resolves the person
 * and hands the rest to `NotificationDispatcher`, so a queued message obeys the
 * same mute, writes the same log rows and swallows a push failure exactly as an
 * inline one does.
 */
class SendManualNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Three, because the dispatcher already swallows the ordinary failures —
     * a muted channel, a dead token, a Firebase outage — and returns false
     * rather than throwing. A job that reaches this class's `catch` has hit
     * something real, and something real is usually transient.
     */
    public $tries = 3;

    /** @var array<int, int> */
    public $backoff = [10, 30];

    public function __construct(
        private readonly int $recipientId,
        private readonly string $title,
        private readonly string $body,
        private readonly int $senderId,
        private readonly string $audience,
    ) {}

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $recipient = User::find($this->recipientId);
        $sender = User::find($this->senderId);

        if ($recipient === null || $sender === null) {
            // Deleted between the send and the worker reaching it. Not a failure
            // worth retrying — the person it was for is gone.
            Log::info('[notifications] manual send skipped, account no longer exists', [
                'recipient' => $this->recipientId,
                'sender' => $this->senderId,
            ]);

            return;
        }

        $dispatcher->send($recipient, new NotificationMessage(
            event: NotificationEvent::ManualMessage,
            title: $this->title,
            body: $this->body,
            data: ['audience' => $this->audience],
            sentBy: $sender,
        ));
    }
}
