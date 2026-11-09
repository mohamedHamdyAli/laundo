<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Notification\Services\PanelAudience;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\TaskHold;
use App\Modules\Order\Repositories\OrderRepository;
use App\Notifications\AdminNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;

/**
 * «بانتظار موافقة الإدارة» — a leg confirmed with a figure that does not match
 * is held, not completed (the client's sheet, items 9 and 10, 2026-11-08).
 *
 * The owner's answers:
 *
 * - **Item 9** — «يجب وقف إكتمال المهمة عند المندوب في حالة عدم تطابق العدد عند
 *   الإستلام من المغسلة»: the collection from the laundry counting differently
 *   from the laundry's own count (`PieceCheck::expectedFor()`) is held.
 * - **Item 10** — «عدم إتمام عملية التحصيل من العميل في حالة وجود مبلغ أقل أو
 *   أكثر إلا بعد موافقة الأدمن»: cash taken at the door that is not exactly
 *   what the leg was to take (`OrderTask::toCollect()`) is held — more as well
 *   as less; «more» used to be refused outright.
 * - **Held, not refused**: the leg stays started, the platform is told, and an
 *   approval completes it with what the driver sent — nothing is asked of the
 *   driver again. A rejection hands it back to recount or collect again
 *   (`TaskService::approveHold()` / `rejectHold()`).
 *
 * This class decides and records; completing is `TaskService`'s, which is the
 * one place a leg completes.
 */
class TaskHolds
{
    use Localizable;

    public function __construct(
        private readonly PieceCheck $pieceCheck,
        private readonly OrderRepository $orders,
    ) {}

    /**
     * Whether this confirmation must wait for the platform.
     *
     * @param  array<string, mixed>  $data  as validated, after `paymentAtTheDoor()`
     * @return array{kind: string, reported: float, expected: float}|null
     */
    public function needed(OrderTask $task, array $data): ?array
    {
        if ($task->type === TaskType::CollectFromLaundry && isset($data['piece_count'])) {
            [$expected] = $this->pieceCheck->expectedFor($task);

            if ($expected !== null && (int) $data['piece_count'] !== (int) $expected) {
                return ['kind' => TaskHold::PIECE_COUNT, 'reported' => (float) (int) $data['piece_count'], 'expected' => (float) $expected];
            }
        }

        $collected = $data['collected_amount'] ?? null;

        if ($task->type->collectsPayment() && $collected !== null && $collected !== '') {
            $due = $task->toCollect();

            if (abs((float) $collected - $due) > 0.001) {
                return ['kind' => TaskHold::COLLECTED_AMOUNT, 'reported' => round((float) $collected, 2), 'expected' => $due];
            }
        }

        return null;
    }

    /**
     * Record the hold — one pending per leg: a driver sending another figure
     * that still does not match replaces the figure, it does not queue a
     * second question.
     *
     * @param  array{kind: string, reported: float, expected: float}  $need
     * @param  array<string, mixed>  $payload  the confirmation, files as stored paths
     */
    public function raise(OrderTask $task, Driver $driver, array $need, array $payload): TaskHold
    {
        $hold = DB::transaction(function () use ($task, $driver, $need, $payload) {
            // Under the leg's lock, like a completion: two «تأكيد» at once must
            // not raise two questions about one leg — and a leg that completed
            // or was cancelled meanwhile (an approval landing first) is not one
            // to ask about.
            $locked = $this->orders->lockTask($task->id);

            if (! $locked || $locked->status !== TaskStatus::Started || (int) $locked->driver_id !== (int) $driver->id) {
                throw new RuntimeException('task_not_started');
            }

            $pending = TaskHold::query()
                ->where('order_task_id', $task->id)
                ->pending()
                ->lockForUpdate()
                ->first();

            $values = [
                'kind' => $need['kind'],
                'reported' => $need['reported'],
                'expected' => $need['expected'],
                'payload' => $payload,
                'raised_by' => $driver->id,
            ];

            return $pending
                ? tap($pending)->update($values)
                : TaskHold::create($values + [
                    'order_task_id' => $task->id,
                    'order_id' => $task->order_id,
                    'status' => TaskHold::PENDING,
                ]);
        });

        $this->announce($hold);

        return $hold;
    }

    /**
     * The driver confirmed again, matching this time — the question nobody
     * answered no longer needs one. In the completion's own transaction.
     */
    public function withdrawPending(OrderTask $task): void
    {
        TaskHold::query()
            ->where('order_task_id', $task->id)
            ->pending()
            ->update(['status' => TaskHold::WITHDRAWN, 'updated_at' => now()]);
    }

    public function latestFor(OrderTask $task): ?TaskHold
    {
        return TaskHold::query()->where('order_task_id', $task->id)->latest('id')->first();
    }

    /**
     * Who decides: the platform — money to whoever handles payments, a count
     * to whoever works orders. Never a laundry: the count it is measured
     * against is its own.
     */
    public static function permissionFor(TaskHold $hold): string
    {
        return $hold->isMoney() ? 'payment.update' : 'order.update';
    }

    /**
     * The bell, in the panel's language (the request is the driver app's).
     * Never fails the hold: it is recorded either way, and the order screen
     * shows it.
     */
    private function announce(TaskHold $hold): void
    {
        try {
            $recipients = PanelAudience::platform(self::permissionFor($hold));

            if ($recipients->isEmpty()) {
                return;
            }

            $order = $hold->order;

            $notice = $this->withLocale((string) getDefaultLanguage('code'), fn () => new AdminNotification(
                __('Waiting for your approval — order #:code', ['code' => $order->code]),
                $hold->describe(),
                // A path, never `route()` — see NotificationUrlTest.
                "/admin/order/show/{$order->id}",
                ['order_id' => (string) $order->id, 'task_hold_id' => (string) $hold->id],
            ));

            Notification::send($recipients, $notice);
        } catch (\Throwable $e) {
            Log::warning('[notifications] task hold notice failed', ['task_hold_id' => $hold->id, 'error' => $e->getMessage()]);
        }
    }
}
