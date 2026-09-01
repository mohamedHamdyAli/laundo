<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\User\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «طلب التأجيل» — the customer picks a new time.
 *
 * Before this, a driver recording a postponement sent the task straight back to
 * the queue, so dispatch offered the same journey to the next driver within
 * seconds. Nobody was waiting on anything; the same failure simply happened again.
 *
 * The owner's decision: the customer chooses. So the postponed leg stops, the
 * order's slot is cleared, and nothing dispatches until this service is called.
 *
 * Two rules worth knowing before changing it:
 *
 *   1. **Only a leg that was actually postponed can be rescheduled.** A task that
 *      failed because the address was wrong is not a scheduling problem, and
 *      letting a customer rebook it would hide a data error behind a new date.
 *   2. **The new slot is validated against the same rules as the original.** A
 *      customer choosing yesterday, or a slot that does not exist, is refused
 *      rather than accepted and quietly ignored by dispatch.
 */
class RescheduleService
{
    public function __construct(private readonly DriverDispatcher $dispatcher) {}

    /**
     * Whether this order is waiting for the customer to pick a new time.
     *
     * The app needs it to decide whether to show the prompt at all, and the
     * dashboard uses it to explain why an order is sitting still.
     */
    public function isAwaitingNewSlot(Order $order): bool
    {
        return $this->postponedTask($order) !== null;
    }

    /**
     * The leg that was postponed, if the order is waiting on one.
     *
     * A failed task whose reason is a postponement and which nothing has replaced.
     */
    public function postponedTask(Order $order): ?OrderTask
    {
        return $order->tasks()
            ->where('status', TaskStatus::Failed->value)
            ->where('failure_reason', TaskFailureReason::CustomerPostponed->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Set a new time and put the journey back in play.
     *
     * @param  array{slot_id: int, date: string}  $data
     */
    public function reschedule(Order $order, User $customer, array $data): Order
    {
        if ((int) $order->user_id !== (int) $customer->id) {
            throw new RuntimeException('not_your_order');
        }

        $task = $this->postponedTask($order);

        if ($task === null) {
            throw new RuntimeException('nothing_to_reschedule');
        }

        // Which end of the order this leg belongs to decides which slots are even
        // eligible: a slot marked delivery-only cannot be used for a collection.
        $collection = in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true);

        $slot = TimeSlot::where('status', 'active')
            ->whereIn('applies_to', ['both', $collection ? 'pickup' : 'delivery'])
            ->find($data['slot_id']);

        if ($slot === null) {
            throw new RuntimeException('slot_not_available');
        }

        $date = Carbon::parse($data['date'])->startOfDay();

        if ($date->lessThan(now()->startOfDay())) {
            // A date in the past is not a reschedule; it is a value dispatch would
            // silently never act on.
            throw new RuntimeException('date_in_the_past');
        }

        return DB::transaction(function () use ($order, $task, $slot, $date, $collection) {
            // Writing the wrong end would leave the postponed half unscheduled
            // while looking fixed.
            $order->forceFill($collection
                ? ['pickup_slot_id' => $slot->id, 'pickup_date' => $date->toDateString()]
                : ['delivery_slot_id' => $slot->id, 'delivery_date' => $date->toDateString()])->save();

            // A fresh attempt, not a retry of the failed one: the attempt counter
            // is reset so a postponement cannot exhaust a task towards escalation.
            // The customer choosing a better time is not the driver failing.
            $task->update([
                'status' => TaskStatus::Pending,
                'driver_id' => null,
                'failure_reason' => null,
                'failure_note' => null,
                'attempts' => 0,
                'due_at' => $date->copy()->setTimeFromTimeString($slot->start_time ?? '09:00'),
            ]);

            // Offered immediately — but for the new time, which is the difference
            // between this and what used to happen.
            $this->dispatcher->dispatch($task->refresh());

            return $order->fresh();
        });
    }
}
