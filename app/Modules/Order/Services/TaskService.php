<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderMedia;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Payment\Services\EarningService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * What a driver does to a task.
 *
 * Start, verify, complete, fail. Two invariants run through all of it:
 *
 *  - **A leg cannot act before the one before it has completed.** Checked on
 *    every call, not only on start, because a task can sit half-done while its
 *    predecessor is undone by an operator.
 *  - **The order's status is only ever changed through OrderStateMachine.** A
 *    completed leg asks the enum what status it produces and hands that over;
 *    it never writes `$order->status` itself.
 */
class TaskService
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly DriverDispatcher $dispatcher,
        private readonly EarningService $earnings,
    ) {}

    /**
     * «بدء المهمة».
     */
    public function start(OrderTask $task, Driver $driver): OrderTask
    {
        $this->assertHolder($task, $driver);

        if (! $task->status->isStartable()) {
            throw new RuntimeException('task_not_startable');
        }

        if (! $task->predecessorComplete()) {
            // Nothing can be delivered that was never collected.
            throw new RuntimeException('previous_leg_incomplete');
        }

        $task->update(['status' => TaskStatus::Started, 'started_at' => now()]);

        // «في الطريق للاستلام» — the customer's tracking screen should say so
        // while the driver is on the way, not after they arrive.
        $this->advanceOrder($task, $driver, $task->type->startsInto());

        return $task->refresh();
    }

    /**
     * «مسح رمز الطلب» — the QR check.
     *
     * The token identifies the order, so scanning the wrong parcel is caught
     * before anything is signed for. Deliberately a comparison against the
     * order's own token rather than a lookup by token: the driver is confirming
     * *this* task's order, not discovering which order they are holding.
     */
    public function verify(OrderTask $task, Driver $driver, string $token): bool
    {
        $this->assertHolder($task, $driver);

        $expected = $task->order?->qr_token;

        if (! $expected || ! hash_equals($expected, $token)) {
            throw new RuntimeException('qr_mismatch');
        }

        return true;
    }

    /**
     * «تأكيد» — the leg is done.
     *
     * @param  array{piece_count?: int|null, receiver_name?: string|null, collected_amount?: float|null, note?: string|null}  $data
     * @param  array<int, UploadedFile>  $photos
     */
    public function complete(
        OrderTask $task,
        Driver $driver,
        array $data = [],
        array $photos = [],
        ?UploadedFile $signature = null,
    ): OrderTask {
        $this->assertHolder($task, $driver);

        if ($task->status !== TaskStatus::Started) {
            throw new RuntimeException('task_not_started');
        }

        if (! $task->predecessorComplete()) {
            throw new RuntimeException('previous_leg_incomplete');
        }

        $type = $task->type;

        if ($type->requiresSignature() && ! $signature && ! $task->signature_path) {
            // The design marks every optional field «(اختياري)» and marks neither
            // signature pad. A handover to a person is proved by that person.
            throw new RuntimeException('signature_required');
        }

        if ($type->countsPieces() && ($data['piece_count'] ?? null) === null) {
            throw new RuntimeException('piece_count_required');
        }

        return DB::transaction(function () use ($task, $driver, $data, $photos, $signature, $type) {
            $update = [
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
                'note' => $data['note'] ?? $task->note,
            ];

            if ($type->countsPieces()) {
                $update['piece_count'] = (int) $data['piece_count'];
            }

            if ($type === TaskType::DeliverToLaundry) {
                $update['receiver_name'] = $data['receiver_name'] ?? null;
            }

            if ($signature) {
                $update['signature_path'] = uploadOrUpdateImage(
                    $signature, 'images/orders/signatures', $task->signature_path
                );
            }

            if ($type->collectsPayment() && array_key_exists('collected_amount', $data)
                && $data['collected_amount'] !== null) {
                $update['collected_amount'] = round((float) $data['collected_amount'], 2);
            }

            $task->update($update);

            foreach ($photos as $photo) {
                $path = uploadOrUpdateImage($photo, 'images/orders/tasks');

                if ($path) {
                    OrderMedia::create([
                        'order_id' => $task->order_id,
                        'type' => $type->mediaType(),
                        'path' => $path,
                        'uploaded_by' => $driver->id,
                    ]);
                }
            }

            $this->settlePayment($task);

            // «تمت إضافة أرباحك إلى الرصيد المعلق» — pending until the order
            // completes, because paying for a delivery that is later returned
            // would have to be clawed back from somebody who has spent it.
            $this->earnings->recordFor($task->refresh());

            $this->advanceOrder($task, $driver, $task->type->completesInto());

            return $task->refresh();
        });
    }

    /**
     * «تعذر الاستلام».
     *
     * Where the task goes next was the business decision: back to the pool, unless
     * the count is in dispute (which halts the order instead) or the task has
     * already failed twice (which hands it to a person).
     */
    public function fail(
        OrderTask $task,
        Driver $driver,
        TaskFailureReason $reason,
        ?string $note = null,
    ): OrderTask {
        $this->assertHolder($task, $driver);

        if ($task->status->isFinished()) {
            throw new RuntimeException('task_finished');
        }

        return DB::transaction(function () use ($task, $driver, $reason, $note) {
            $task->update([
                'failure_reason' => $reason,
                'failure_note' => $note,
                'attempts' => $task->attempts + 1,
                'completed_at' => null,
            ]);

            $task->refresh();

            if ($reason->needsANewSlot()) {
                // The customer said "not now". Releasing it — which is what used
                // to happen — offered the same journey to the next driver within
                // seconds. That is not a retry, it is the same failure again.
                //
                // So the task stops here and the order's slot is cleared. Nothing
                // dispatches until the customer picks a time, and the cleared slot
                // is what makes that unmistakable to anybody reading the row.
                $task->update(['status' => TaskStatus::Failed, 'driver_id' => null]);

                $this->clearSchedule($task);

                $this->machine->note(
                    $task->order,
                    __('Postponed by the customer. Waiting for a new time.').($note ? " — {$note}" : ''),
                    'driver',
                    $driver
                );

                $this->askForANewSlot($task->order);

                return $task->refresh();
            }

            if ($reason->haltsTheOrder()) {
                // A disagreement about the count is not a delivery problem, and
                // sending another driver would move clothes already in dispute.
                $task->update(['status' => TaskStatus::Failed, 'driver_id' => null]);

                $this->machine->note(
                    $task->order,
                    __('Delivery halted: :reason', ['reason' => __($reason->label())]).($note ? " — {$note}" : ''),
                    'driver',
                    $driver
                );

                return $task->refresh();
            }

            if ($task->isExhausted()) {
                // Two failures is a problem with the task, whoever holds it.
                $task->update(['status' => TaskStatus::Failed, 'driver_id' => null]);

                $this->machine->note(
                    $task->order,
                    __('Task failed :count times and needs attention.', ['count' => $task->attempts]),
                    'system'
                );

                return $task->refresh();
            }

            // Back to the queue, and offered straight away to anyone else eligible.
            $this->dispatcher->release($task);
            $this->dispatcher->dispatch($task->refresh());

            $this->machine->note(
                $task->order,
                __('Attempt failed: :reason', ['reason' => __($reason->label())]).($note ? " — {$note}" : ''),
                'driver',
                $driver
            );

            return $task->refresh();
        });
    }

    /**
     * Move the order on, if this leg moves it.
     *
     * Legs 2 and 3 move nothing — after the hand-over the order waits on the
     * laundry's review, and after the collection it is already ready. Asking the
     * enum keeps that knowledge in one place.
     */
    private function advanceOrder(OrderTask $task, Driver $driver, ?OrderStatus $target): void
    {
        if (! $target) {
            return;
        }

        $order = $task->order;

        if (! $order || ! $order->status->canTransitionTo($target)) {
            // The order is somewhere the state machine will not accept this from.
            // Recorded rather than forced: the task genuinely happened.
            $this->machine->note(
                $order,
                __('Leg completed but the order was not in a state to advance.'),
                'driver',
                $driver
            );

            return;
        }

        $this->machine->transition($order, $target, 'driver', $driver, __($task->type->label()));
    }

    /**
     * Cash taken at the door.
     *
     * Marks the order paid only when the full amount arrived. Anything less is
     * recorded and left unpaid, so a short collection surfaces as an unpaid order
     * rather than disappearing into a rounding difference.
     */
    private function settlePayment(OrderTask $task): void
    {
        if (! $task->type->collectsPayment() || $task->collected_amount === null) {
            return;
        }

        $order = $task->order;

        if (! $order || $order->payment_status === 'paid') {
            return;
        }

        $due = $order->payableTotal();
        $collected = (float) $task->collected_amount;

        if ($collected + 0.001 >= $due) {
            $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
        }
    }

    /**
     * A driver may only act on their own task.
     *
     * Belt and braces: the API queries through the driver's own relation, so this
     * should be unreachable — but a service that trusts its caller is a service
     * that breaks the first time somebody calls it from a console command.
     */
    private function assertHolder(OrderTask $task, Driver $driver): void
    {
        if ($task->driver_id !== $driver->id) {
            throw new RuntimeException('not_your_task');
        }
    }

    /**
     * Clear the slot the postponed leg was going to use.
     *
     * Which end depends on the leg: legs 1 and 2 are the collection, 3 and 4 the
     * return. Clearing the wrong one leaves a stale time on the half that was
     * never in question, and asks the customer to rebook something fine.
     */
    private function clearSchedule(OrderTask $task): void
    {
        $order = $task->order;

        if ($order === null) {
            return;
        }

        $collection = in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true);

        $order->forceFill($collection
            ? ['pickup_slot_id' => null, 'pickup_date' => null]
            : ['delivery_slot_id' => null, 'delivery_date' => null])->save();
    }

    /**
     * Ask the customer to choose a new time.
     *
     * Sent through the ordinary dispatcher and swallowed: a postponement that has
     * been correctly recorded must not be undone by a notification vendor. The
     * order also appears in the operations queue, so the message is not the only
     * way anybody finds out.
     */
    private function askForANewSlot(?Order $order): void
    {
        if ($order === null || $order->customer === null) {
            return;
        }

        try {
            app(NotificationDispatcher::class)->send($order->customer, new NotificationMessage(
                event: NotificationEvent::RescheduleNeeded,
                title: __('Pick a new time for your order'),
                body: __('Order :code was postponed. Choose a new time so we can collect it.', [
                    'code' => $order->code,
                ]),
                data: ['order_id' => (string) $order->id],
                subject: $order,
            ));
        } catch (Throwable) {
            // Recorded either way.
        }
    }
}
