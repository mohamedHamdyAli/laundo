<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AdminNotification extends Notification
{
    public function __construct(
        protected string $title,
        protected string $message,
        protected ?string $url = null,
        protected array $meta = [],
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'meta' => $this->meta,
        ];
    }
}
