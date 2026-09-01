<?php

namespace App\Modules\Notification\Data;

use App\Modules\Notification\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing to tell one person.
 *
 * A plain object rather than an array so a message cannot be assembled with a
 * typo'd key and fail silently at the point of delivery, where nobody is looking.
 */
class NotificationMessage
{
    /**
     * @param  array<string, string>  $data  what the app reads to decide where to
     *                                       navigate when the notification is tapped
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly array $data = [],
        public readonly ?Model $subject = null,
    ) {}
}
