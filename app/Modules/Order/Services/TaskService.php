<?php

namespace App\Modules\Order\Services;

use App\Modules\Driver\Models\Driver;
use App\Modules\Loyalty\Services\OrderPoints;
use App\Modules\Notification\Data\NotificationMessage;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Notification\Services\PieceCountNotifier;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderMedia;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Models\TaskHold;
use App\Modules\Order\Repositories\OrderRepository;
use App\Modules\Payment\Enums\PaymentMethod;
use App\Modules\Payment\Enums\PaymentStatus;
use App\Modules\Payment\Models\Payment;
use App\Modules\Payment\Services\EarningService;
use App\Modules\User\Models\User;
use App\Modules\Wallet\Enums\TransactionReason;
use App\Modules\Wallet\Services\WalletService;
use App\Support\LaundryContext;
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
        private readonly PieceCheck $pieceCheck,
        private readonly PieceCountNotifier $pieceNotifier,
        private readonly OrderRepository $orders,
        private readonly OrderPoints $points,
        private readonly TaskHolds $holds,
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

        // Nowhere to take the pieces (no laundry yet), or nothing leaves the
        // laundry before its price is agreed.
        if (($reason = $task->blockedReason()) !== null) {
            throw new RuntimeException($reason);
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
     * @param  array{piece_count?: int|null, receiver_name?: string|null, collected_amount?: float|null, hangers_returned?: int|null, note?: string|null}  $data
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

        // Asked again here, not only at the start: a leg started before the
        // rule existed must not be completed past it. Under way, `cleaning` is
        // enough — a collection started before «خلص الغسيل» existed (item 18)
        // finishes.
        if (! $task->orderAllows(underWay: true)) {
            throw new RuntimeException('order_not_ready');
        }

        $type = $task->type;

        if ($type->collectsPayment()) {
            $data = $this->paymentAtTheDoor($task, $data);
        }

        if ($type->requiresSignature() && ! $signature && ! $task->signature_path) {
            // The design marks every optional field «(اختياري)» and marks neither
            // signature pad. A handover to a person is proved by that person.
            throw new RuntimeException('signature_required');
        }

        if ($type->countsPieces() && ($data['piece_count'] ?? null) === null) {
            throw new RuntimeException('piece_count_required');
        }

        // «شماعات راجعة» — only at the customer's door, and never more than a
        // visit may be credited for. Ignored on the laundry legs, the way
        // `receiver_name` is ignored where nobody signs.
        $hangers = $type->takesHangers() && isset($data['hangers_returned'])
            ? (int) $data['hangers_returned']
            : null;

        if ($hangers !== null && ($hangers < 0 || $hangers > $this->points->hangersAllowed())) {
            throw new RuntimeException('hangers_too_many');
        }

        // Stored before anything is decided: a confirmation held for the
        // platform completes later with exactly these files.
        $signaturePath = $signature ? uploadOrUpdateImage($signature, 'images/orders/signatures') : null;
        $photoPaths = array_values(array_filter(array_map(
            fn (UploadedFile $photo) => uploadOrUpdateImage($photo, 'images/orders/tasks'),
            $photos
        )));

        // «بانتظار موافقة الإدارة» (items 9 and 10): a count at the laundry or
        // cash at the door that does not match is held, not completed.
        if (($need = $this->holds->needed($task, $data)) !== null) {
            throw new TaskHeld($this->holds->raise($task, $driver, $need, [
                'piece_count' => $data['piece_count'] ?? null,
                'receiver_name' => $data['receiver_name'] ?? null,
                'collected_amount' => $data['collected_amount'] ?? null,
                'note' => $data['note'] ?? null,
                'hangers_returned' => $hangers,
                'photos' => $photoPaths,
                'signature' => $signaturePath,
            ]));
        }

        return $this->finish($task, $driver, $data, $hangers, $photoPaths, $signaturePath);
    }

    /**
     * «موافقة» — the platform accepts a held confirmation, and the leg completes
     * with what the driver sent (`TaskHolds`, items 9 and 10).
     *
     * The platform's only: refused inside a laundry, and for any approver who
     * belongs to one, whatever the permission says.
     */
    public function approveHold(TaskHold $hold, User $by, ?string $note = null): OrderTask
    {
        $this->platformOnly($by);

        if (! $hold->isPending()) {
            throw new RuntimeException('nothing_to_decide');
        }

        $task = $hold->task;

        if (! $task || $task->status !== TaskStatus::Started) {
            throw new RuntimeException('task_not_started');
        }

        // Only for the driver who raised it: their count, their cash.
        if ((int) $task->driver_id !== (int) $hold->raised_by) {
            throw new RuntimeException('task_not_started');
        }

        $driver = Driver::find($task->driver_id) ?? throw new RuntimeException('task_has_no_driver');
        $payload = $hold->payload;

        $completed = $this->finish(
            $task,
            $driver,
            array_intersect_key($payload, array_flip(['piece_count', 'receiver_name', 'collected_amount', 'note'])),
            $payload['hangers_returned'] ?? null,
            (array) ($payload['photos'] ?? []),
            $payload['signature'] ?? null,
            $hold,
            $by,
            $note,
        );

        $this->holdDecided($hold->refresh());

        return $completed;
    }

    /**
     * «رفض» — back to the driver to recount or collect again. A note is
     * required: it is what the driver is told.
     */
    public function rejectHold(TaskHold $hold, User $by, string $note): TaskHold
    {
        $this->platformOnly($by);

        if (trim($note) === '') {
            throw new RuntimeException('note_required');
        }

        $rejected = DB::transaction(function () use ($hold, $by, $note) {
            $current = TaskHold::whereKey($hold->id)->lockForUpdate()->first();

            if (! $current || ! $current->isPending()) {
                throw new RuntimeException('nothing_to_decide');
            }

            $current->update([
                'status' => TaskHold::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'note' => trim($note),
            ]);

            $this->machine->note(
                $current->order,
                __('Held confirmation refused: :note', ['note' => trim($note)]),
                'admin',
                $by
            );

            return $current->refresh();
        });

        $this->holdDecided($rejected);

        return $rejected;
    }

    /**
     * The leg completes — from the driver's «تأكيد», or from the platform's
     * approval of one that was held. The one place a leg completes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $photoPaths  stored already
     */
    private function finish(
        OrderTask $task,
        Driver $driver,
        array $data,
        ?int $hangers,
        array $photoPaths,
        ?string $signaturePath,
        ?TaskHold $approved = null,
        ?User $approver = null,
        ?string $approvalNote = null,
    ): OrderTask {
        $type = $task->type;
        $raised = [];

        $completed = DB::transaction(function () use ($task, $driver, $data, $photoPaths, $signaturePath, $type, $hangers, $approved, $approver, $approvalNote, &$raised) {
            // Re-checked under a row lock: the same «تأكيد» sent twice at once —
            // a double tap, a retry on a slow line — would otherwise pass the
            // check above twice and complete the leg twice, its earnings and
            // its piece count with it.
            $locked = $this->orders->lockTask($task->id);

            if (! $locked || $locked->status !== TaskStatus::Started) {
                throw new RuntimeException('task_not_started');
            }

            $update = [
                'status' => TaskStatus::Completed,
                'completed_at' => now(),
                'note' => $data['note'] ?? $task->note,
            ];

            if ($type->countsPieces()) {
                $update['piece_count'] = (int) $data['piece_count'];

                // What the count is measured against, stamped now: the
                // laundry's review can change the order's count later, and the
                // question is what this handover was held to at the time.
                [$expected, $source] = $this->pieceCheck->expectedFor($task);
                $update['expected_piece_count'] = $expected;
                $update['expected_piece_source'] = $source;
            }

            if ($type === TaskType::DeliverToLaundry) {
                $update['receiver_name'] = $data['receiver_name'] ?? null;
            }

            if ($signaturePath) {
                $update['signature_path'] = $signaturePath;
            }

            if ($type->collectsPayment() && array_key_exists('collected_amount', $data)
                && $data['collected_amount'] !== null) {
                $update['collected_amount'] = round((float) $data['collected_amount'], 2);
            }

            if ($hangers !== null) {
                $update['hangers_returned'] = $hangers;
            }

            $task->update($update);

            // Hanger points are no longer paid here: the laundry confirms what
            // reached it, and the points follow its count (`HangerReceipt`,
            // the client's sheet, item 11, 2026-11-08).

            // In the handover's own transaction: a leg recorded without the
            // disagreement it raised would be a mismatch nobody is ever asked
            // to look at.
            if ($type->countsPieces()) {
                $raised = $this->pieceCheck->afterHandover(
                    $task, $update['piece_count'], $update['expected_piece_count'], $update['expected_piece_source'],
                );

                // An approved count was looked into before it was accepted:
                // its disagreement is recorded and closed in one step, by the
                // person who approved it, rather than raised again.
                if ($approved && $approver) {
                    foreach ($raised as $discrepancy) {
                        $this->pieceCheck->resolve(
                            $discrepancy,
                            $approver,
                            $approvalNote ?: __('Approved with the held count.'),
                            (int) $update['piece_count'],
                        );
                    }

                    $raised = [];
                }
            }

            foreach ($photoPaths as $path) {
                OrderMedia::create([
                    'order_id' => $task->order_id,
                    'type' => $type->mediaType(),
                    'path' => $path,
                    'uploaded_by' => $driver->id,
                ]);
            }

            if ($approved && $approver) {
                $approved->update([
                    'status' => TaskHold::APPROVED,
                    'decided_by' => $approver->id,
                    'decided_at' => now(),
                    'note' => $approvalNote,
                ]);

                $this->machine->note(
                    $task->order,
                    __('Held confirmation approved: :what', ['what' => $approved->describe()]).($approvalNote ? " — {$approvalNote}" : ''),
                    'admin',
                    $approver
                );
            } else {
                // Confirmed again, matching this time: the open question goes.
                $this->holds->withdrawPending($task);
            }

            $this->settlePayment($task, $approver);

            // «تمت إضافة أرباحك إلى الرصيد المعلق» — pending until the order
            // completes, because paying for a delivery that is later returned
            // would have to be clawed back from somebody who has spent it.
            $this->earnings->recordFor($task->refresh());

            $this->advanceOrder($task, $driver, $task->type->completesInto());

            // Delivered is the clothes, Completed is the money, and on a cash
            // order both land in this one call — `settlePayment()` above marked
            // it paid, `advanceOrder()` has just marked it delivered. Asked
            // after both, so the order does not have to wait for a second event
            // that is never coming.
            $this->machine->completeIfPaid($task->order->refresh(), 'driver', $driver);

            return $task->refresh();
        });

        // After the commit: a handover rolled back must not have announced a
        // mismatch in a count that was never recorded.
        foreach ($raised as $discrepancy) {
            $this->pieceNotifier->mismatch($discrepancy);
        }

        return $completed;
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
            // A confirmation held for the platform on this leg is moot once the
            // driver gives the leg up (code review, 2026-11-08).
            $this->holds->withdrawPending($task);

            $task->update([
                'failure_reason' => $reason,
                'failure_note' => $note,
                'attempts' => $task->attempts + 1,
                'completed_at' => null,
            ]);

            $task->refresh();

            if ($reason->needsANewSlot($task->type)) {
                // The customer said "not now". Releasing it — which is what used
                // to happen — offered the same journey to the next driver within
                // seconds. That is not a retry, it is the same failure again.
                //
                // So the task stops here and the order's slot is cleared. Nothing
                // dispatches until the customer picks a time, and the cleared slot
                // is what makes that unmistakable to anybody reading the row.
                //
                // The customer not being there for the pickup takes the same
                // path from the first time (the client's sheet, item 7): only
                // the pickup's time is asked for again — the delivery follows
                // on its own when it must, the service stays — and the trip is
                // not charged twice.
                $task->update(['status' => TaskStatus::Failed, 'driver_id' => null]);

                // The order leaves its visit — the trip that visit still makes
                // for another of the customer's orders is that order's to pay
                // now, before the window it is read from is cleared (code
                // review, 2026-11-08). This order keeps its own fee for the
                // time it picks: the missed trip is not charged twice.
                app(OrderService::class)->handOverTripFees(
                    $task->order,
                    [$this->isCollectionEnd($task->type) ? 'pickup' : 'return'],
                    wasCancelled: false,
                );

                $this->clearSchedule($task);

                $absent = $reason === TaskFailureReason::CustomerUnavailable;

                $this->machine->note(
                    $task->order,
                    ($absent
                        ? __('The customer was not there for the pickup. Waiting for them to pick a new time.')
                        : __('Postponed by the customer. Waiting for a new time.')).($note ? " — {$note}" : ''),
                    'driver',
                    $driver
                );

                $this->askForANewSlot($task->order, $absent);

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

            // Back to the queue, and offered straight away to anyone else
            // eligible — or left for a person, with automatic assignment off.
            $this->dispatcher->release($task);
            $this->dispatcher->automatically($task->refresh());

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

        // Read again, under a lock. The customer may be agreeing the price this
        // very moment: a stale `reviewed` here refuses the move to `cleaning`,
        // and the confirmation — which cannot see this uncommitted leg — would
        // not make it either, leaving the order at `confirmed` for good.
        // `OrderReviewService::confirm()` takes the same lock.
        $order = $task->order()->lockForUpdate()->first();

        if ($order) {
            $task->setRelation('order', $order);
        }

        // A collection started at `cleaning`, before «خلص الغسيل» existed
        // (item 18): the driver has the pieces, so the laundry finished — said
        // first, so the history and the customer's line carry the step.
        if ($order && $order->status === OrderStatus::Cleaning && $target === OrderStatus::ReadyForDelivery) {
            $order = $this->machine->transition($order, OrderStatus::Finished, 'driver', $driver, __($task->type->label()));
        }

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
     * What the driver says changed hands at the door, held to the order.
     *
     * On an order not yet paid the amount is required — 0 when the customer
     * paid nothing — because a delivery closed without it left the order unpaid
     * for ever: never `Completed`, never settled, the driver's bonus never
     * released (four on live, 2026-10-01). Never more than is due: 250 typed
     * against 48 is a slip, not a tip. On an order already paid nothing changes
     * hands, and a figure sent anyway is dropped rather than recorded as cash
     * that never existed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function paymentAtTheDoor(OrderTask $task, array $data): array
    {
        $collected = $data['collected_amount'] ?? null;
        $reported = $collected !== null && $collected !== '';

        // Nothing to take here — an order already paid, or a pickup with no trip
        // fee on it (paid online, or priced by distance): a figure sent anyway is
        // dropped rather than recorded as cash that never existed.
        if ($task->toCollect() <= 0 && ! $task->requiresCollectedAmount()) {
            unset($data['collected_amount']);

            return $data;
        }

        if (! $reported) {
            if ($task->requiresCollectedAmount()) {
                throw new RuntimeException('collected_amount_required');
            }

            // A pickup that says nothing: the trip fee stays owed and the
            // delivery takes it with the rest.
            unset($data['collected_amount']);

            return $data;
        }

        // Not refused when it differs from what this leg takes — less or more —
        // but held for the platform's approval (`TaskHolds`, item 10). «More»
        // used to be refused here outright.
        return $data;
    }

    /**
     * Cash taken at the door.
     *
     * Marks the order paid only when the full amount arrived. Anything less is
     * recorded and left unpaid, so a short collection surfaces as an unpaid order
     * rather than disappearing into a rounding difference.
     *
     * Every pound collected is also a captured `cash` payment in the driver's
     * name. Before, cash was a flag on the order and nothing else: the payments
     * screen never saw it, and nobody could say how much each driver was holding.
     */
    private function settlePayment(OrderTask $task, ?User $approver = null): void
    {
        if (! $task->type->collectsPayment() || $task->collected_amount === null) {
            return;
        }

        $order = $task->order;

        if (! $order || $order->payment_status === 'paid') {
            return;
        }

        $collected = (float) $task->collected_amount;
        $payment = null;

        if ($collected > 0) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'provider' => 'cash',
                'method' => PaymentMethod::Cash,
                'amount' => $collected,
                'currency' => appCurrency(),
                'status' => PaymentStatus::Captured,
                'captured_at' => now(),
                'collected_by' => $task->driver_id,
                'order_task_id' => $task->id,
            ]);
        }

        // Never decided at the pickup: there is no price yet, only the
        // estimate. A pickup whose cash covered the estimate marked the order
        // paid before the laundry counted, and the counted price was never
        // collected — the delivery was told to take 0 (security review,
        // 2026-11-08). What the pickup took is a payment towards the bill, and
        // the delivery settles the rest against the price that was agreed.
        if ($task->type === TaskType::PickupFromCustomer) {
            return;
        }

        // Paid when everything taken for it — the pickup's trip fee and this —
        // covers the bill.
        if ($order->paidSoFar() + 0.001 >= $order->payableTotal()) {
            $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
        }

        // More than was owed, and the platform approved taking it (item 10;
        // the owner: «الزيادة محفظة»): the cash stays the driver's to hand in,
        // all of it, and what the customer overpaid is theirs to spend — a
        // wallet credit, said under its own reason. Short is simply left owed.
        // Measured here, against the agreed bill, whichever leg took the extra:
        // cash over the trip fee at the pickup is only known to be over once
        // the pieces are counted. Every pound above a leg's figure went through
        // an approval, so the extra is credited at the leg that settles.
        $excess = round($order->paidSoFar() - $order->payableTotal(), 2);

        if ($excess > 0.001 && $order->customer) {
            app(WalletService::class)->credit(
                $order->customer,
                $excess,
                TransactionReason::OverCollected,
                $payment ?? $order->payments()->where('status', PaymentStatus::Captured->value)->latest('id')->first(),
                __('Paid over the bill at the door on order #:code', ['code' => $order->code]),
                $approver,
            );
        }
    }

    /**
     * A driver may only act on their own task.
     *
     * Belt and braces: the API queries through the driver's own relation, so this
     * should be unreachable — but a service that trusts its caller is a service
     * that breaks the first time somebody calls it from a console command.
     */
    private function platformOnly(User $by): void
    {
        if ($by->laundry_id !== null || LaundryContext::currentId() !== null) {
            throw new RuntimeException('platform_only');
        }
    }

    /**
     * Tell the driver what became of their held «تأكيد». Swallowed: the
     * decision is recorded either way, and the task screen shows it.
     */
    private function holdDecided(TaskHold $hold): void
    {
        $driverId = $hold->task?->driver_id ?? $hold->raised_by;
        $driver = $driverId ? Driver::find($driverId) : null;

        if (! $driver) {
            return;
        }

        $approved = $hold->status === TaskHold::APPROVED;

        try {
            app(NotificationDispatcher::class)->send($driver, new NotificationMessage(
                event: NotificationEvent::TaskHoldDecided,
                title: $approved ? __('Your confirmation was approved') : __('Your confirmation was refused'),
                body: $approved
                    ? __('Order :code: the leg is complete.', ['code' => $hold->order?->code])
                    : __('Order :code: :note', ['code' => $hold->order?->code, 'note' => (string) $hold->note]),
                url: "/driver/tasks/{$hold->order_task_id}",
                data: ['order_id' => (string) $hold->order_id, 'task_id' => (string) $hold->order_task_id],
                subject: $hold,
            ));
        } catch (Throwable) {
            // Recorded either way.
        }
    }

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

        $order->forceFill($this->isCollectionEnd($task->type)
            ? ['pickup_slot_id' => null, 'pickup_date' => null]
            : ['delivery_slot_id' => null, 'delivery_date' => null])->save();
    }

    /**
     * Whether a leg belongs to the order's collection end (the pickup's window)
     * rather than its return end (the delivery's).
     */
    private function isCollectionEnd(TaskType $type): bool
    {
        return in_array($type, [TaskType::PickupFromCustomer, TaskType::DeliverToLaundry], true);
    }

    /**
     * Ask the customer to choose a new time.
     *
     * Sent through the ordinary dispatcher and swallowed: a postponement that has
     * been correctly recorded must not be undone by a notification vendor. The
     * order also appears in the operations queue, so the message is not the only
     * way anybody finds out.
     */
    private function askForANewSlot(?Order $order, bool $absent = false): void
    {
        if ($order === null || $order->customer === null) {
            return;
        }

        try {
            app(NotificationDispatcher::class)->send($order->customer, new NotificationMessage(
                event: NotificationEvent::RescheduleNeeded,
                title: __('Pick a new time for your order'),
                body: $absent
                    ? __('Our driver could not find you to collect order :code. Choose a new pickup time.', ['code' => $order->code])
                    : __('Order :code was postponed. Choose a new time so we can collect it.', [
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
