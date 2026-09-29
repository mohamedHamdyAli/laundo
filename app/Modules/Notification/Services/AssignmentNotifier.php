<?php

namespace App\Modules\Notification\Services;

use App\Modules\Order\Models\Order;
use App\Notifications\AdminNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;

/**
 * Work waiting for a person because automatic assignment is switched off.
 *
 * With the switch on, nobody needs telling: the platform hands the order to a
 * laundry and the legs to drivers by itself. Switched off, the same work sits
 * until somebody picks — and a queue nobody is told about is a queue nobody
 * looks at, so each order that lands on it is announced in the panel's bell to
 * the people who can act on it: the super admin, and anybody at the platform
 * holding the permission the assigning screen asks for.
 *
 * The panel's own bell (`AdminNotification`), not push — the people assigning
 * work sit at the panel, not at a phone app. Failures are swallowed and logged:
 * the order is placed and visible on the home page and the sidebar badge either
 * way, and a placement must never fail because an announcement did.
 */
class AssignmentNotifier
{
    use Localizable;

    /**
     * An order was placed with no laundry, because the switch is off.
     */
    public function laundryWaiting(Order $order): void
    {
        $this->send(
            'order.update',
            fn () => [
                __('An order is waiting for a laundry'),
                __('Order #:code was placed while automatic laundry assignment is off. Choose its laundry from the order.', ['code' => $order->code]),
            ],
            // A path, never `route()` — see NotificationUrlTest.
            "/admin/order/show/{$order->id}",
            ['order_id' => (string) $order->id],
        );
    }

    /**
     * An order's legs are waiting for a driver, because the switch is off.
     */
    public function tripsWaiting(Order $order, int $count): void
    {
        if ($count < 1) {
            return;
        }

        $this->send(
            'order_task.update',
            fn () => [
                __('Trips are waiting for a driver'),
                __('Order #:code has :count trip(s) waiting for a driver — automatic driver assignment is off.', [
                    'code' => $order->code,
                    'count' => $count,
                ]),
            ],
            '/admin/dispatch',
            ['order_id' => (string) $order->id],
        );
    }

    /**
     * To the platform only — a laundry cannot assign its own work — in the
     * panel's language, since the request raising it is often a customer's
     * app in theirs, and the bell keeps the words as they were written.
     *
     * Everything inside the try, the recipient query included: this runs as
     * part of placing an order or setting a laundry, and neither may fail
     * because an announcement did.
     *
     * @param  callable(): array{0: string, 1: string}  $message  title and body
     * @param  array<string, string>  $data
     */
    private function send(string $permission, callable $message, string $url, array $data): void
    {
        try {
            $recipients = PanelAudience::platform($permission);

            if ($recipients->isEmpty()) {
                return;
            }

            [$title, $body] = $this->withLocale((string) getDefaultLanguage('code'), $message);

            Notification::send($recipients, new AdminNotification($title, $body, $url, $data));
        } catch (\Throwable $e) {
            Log::warning('[notifications] assignment notice failed', ['error' => $e->getMessage()]);
        }
    }
}
