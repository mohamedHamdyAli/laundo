<?php

namespace App\Modules\Notification\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\PieceDiscrepancy;
use App\Notifications\AdminNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;

/**
 * Two counts of the same pieces disagreed — a driver at a handover, or the
 * laundry at its review.
 *
 * Two audiences, both in the panel's bell (`AdminNotification`): the platform —
 * the super admin and anybody not inside a laundry holding `order.update`, who
 * are the ones to look into it — and the order's own laundry, whose pieces they
 * are, whoever there can see its orders. Picked by the order's `laundry_id`
 * (PanelAudience), never by a scope the request happens to carry, because the
 * driver completing the leg has no laundry at all.
 *
 * **Written in the panel's language**, not the request's: the request is the
 * driver app's, in whatever language the driver reads, and the bell stores the
 * words as they were written — an English phone would otherwise leave an
 * Arabic panel reading English for good.
 *
 * **Nothing here can fail the handover or the review.** It runs after they
 * committed, so everything — the recipient queries included, not only the
 * send — is caught and logged: the disagreement is on the order and counted in
 * the sidebar either way, and a driver told «failed» about a handover that was
 * in fact recorded would try again and be refused.
 */
class PieceCountNotifier
{
    use Localizable;

    public function mismatch(PieceDiscrepancy $discrepancy): void
    {
        try {
            $order = $discrepancy->order;

            $recipients = PanelAudience::platform('order.update')
                ->merge(PanelAudience::laundry($order->laundry_id, 'order.view'))
                ->unique('id');

            if ($recipients->isEmpty()) {
                return;
            }

            $notice = $this->withLocale((string) getDefaultLanguage('code'), fn () => new AdminNotification(
                __('Piece count does not match — order #:code', ['code' => $order->code]),
                self::describe($discrepancy),
                // A path, never `route()` — see NotificationUrlTest.
                "/admin/order/show/{$order->id}",
                ['order_id' => (string) $order->id, 'piece_discrepancy_id' => (string) $discrepancy->id],
            ));

            Notification::send($recipients, $notice);
        } catch (\Throwable $e) {
            Log::warning('[notifications] piece count notice failed', [
                'piece_discrepancy_id' => $discrepancy->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The driver gave up a leg because the count does not match
     * («تعذر الاستلام — عدد القطع غير مطابق»), which halts the order. The
     * platform is told so somebody takes it up with the laundry (the client,
     * item 16, 2026-11-24). The platform only: it is the platform's
     * conversation with the laundry, not the laundry's own bell.
     */
    public function collectionStopped(OrderTask $task, Driver $driver, ?string $note = null): void
    {
        try {
            $order = $task->order;
            $recipients = PanelAudience::platform('order.update');

            if (! $order || $recipients->isEmpty()) {
                return;
            }

            // The reason can be given on any leg; only a leg at the laundry is
            // the laundry's to answer for (code review).
            $atTheLaundry = in_array($task->type, [TaskType::DeliverToLaundry, TaskType::CollectFromLaundry], true)
                && $order->laundry !== null;

            $notice = $this->withLocale((string) getDefaultLanguage('code'), fn () => new AdminNotification(
                __('Order stopped: piece count does not match — order #:code', ['code' => $order->code]),
                ($atTheLaundry
                    ? __(':driver could not complete «:leg»: the count does not match. Follow it up with :laundry.', [
                        'driver' => $driver->name,
                        'leg' => __($task->type->label()),
                        'laundry' => getLocalizedValueDashboard($order->laundry, 'name'),
                    ])
                    : __(':driver could not complete «:leg»: the count does not match.', [
                        'driver' => $driver->name,
                        'leg' => __($task->type->label()),
                    ])).($note ? " — {$note}" : ''),
                // A path, never `route()` — see NotificationUrlTest.
                "/admin/order/show/{$order->id}",
                ['order_id' => (string) $order->id, 'order_task_id' => (string) $task->id],
            ));

            Notification::send($recipients, $notice);
        } catch (\Throwable $e) {
            Log::warning('[notifications] collection stopped notice failed', [
                'order_task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The one sentence, for the bell and for the order screen alike.
     */
    public static function describe(PieceDiscrepancy $discrepancy): string
    {
        if ($discrepancy->isReview()) {
            return __('The laundry counted :counted at its review, against :expected handed over to it.', [
                'counted' => $discrepancy->counted,
                'expected' => $discrepancy->expected,
            ]);
        }

        return __(':driver counted :counted at «:leg», against :expected — :source.', [
            'driver' => $discrepancy->task->driver->name ?? __('The driver'),
            'counted' => $discrepancy->counted,
            'leg' => __($discrepancy->step->label()),
            'expected' => $discrepancy->expected,
            'source' => __($discrepancy->expected_source->label()),
        ]);
    }
}
