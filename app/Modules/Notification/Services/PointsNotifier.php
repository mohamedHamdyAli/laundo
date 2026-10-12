<?php

namespace App\Modules\Notification\Services;

use App\Modules\Loyalty\Enums\PointReason;
use App\Modules\Loyalty\Models\PointTransaction;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\User\Models\User;
use Illuminate\Support\Carbon;

/**
 * «كسبت 10 نقاط» and «عندك 30 نقطة هتنتهي يوم …».
 *
 * Both open the points screen (`/points`). Neither is transactional — nothing
 * waits on them — so a customer who muted the app is not told.
 */
class PointsNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Points were added: an order completed, or hangers came back.
     */
    public function earned(PointTransaction $credit, ?string $orderCode = null, int $hangers = 0): void
    {
        $customer = User::find($credit->user_id);

        if (! $customer) {
            return;
        }

        $points = (int) $credit->points;

        $body = $credit->reason === PointReason::HangersReturned
            ? trans_choice(
                'Thank you for returning :hangers hanger. Your balance is now :balance points.|Thank you for returning :hangers hangers. Your balance is now :balance points.',
                $hangers,
                ['hangers' => $hangers, 'balance' => (int) $credit->balance_after],
            )
            : __('Order :code is complete. Your balance is now :balance points.', [
                'code' => (string) $orderCode,
                'balance' => (int) $credit->balance_after,
            ]);

        $this->dispatcher->send($customer, new NotificationMessage(
            event: NotificationEvent::PointsEarned,
            title: trans_choice('You earned :count point|You earned :count points', $points, ['count' => $points]),
            body: $body,
            url: '/points',
            data: ['points' => (string) $points, 'balance' => (string) $credit->balance_after],
            subject: $credit,
        ));
    }

    /**
     * Some points lapse soon. Once per batch, from `loyalty:expire-points`.
     */
    public function expiring(User $customer, int $points, Carbon $expiresAt): void
    {
        if ($points < 1) {
            return;
        }

        $this->dispatcher->send($customer, new NotificationMessage(
            event: NotificationEvent::PointsExpiring,
            title: __('Your points are about to expire'),
            body: trans_choice(
                ':count point expires on :date. Use it on your next order.|:count points expire on :date. Use them on your next order.',
                $points,
                ['count' => $points, 'date' => humanDate($expiresAt, 'j M Y')],
            ),
            url: '/points',
            data: ['points' => (string) $points, 'expires_at' => isoDate($expiresAt)],
        ));
    }
}
