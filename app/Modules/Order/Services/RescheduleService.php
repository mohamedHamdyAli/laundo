<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\TaskFailureReason;
use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotCapacity;
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
    public function __construct(
        private readonly DriverDispatcher $dispatcher,
        private readonly SlotCapacity $slots,
        private readonly Turnaround $turnaround,
        private readonly TaskGenerator $tasks,
        private readonly OrderStateMachine $machine,
    ) {}

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

        // A delivery rebooked for sooner than the service can turn the pieces
        // round is refused — the same as at checkout (Turnaround). Not held to
        // the booking window: that is measured from the original pickup, and
        // an order postponed weeks later would have no day left to choose.
        if (! $collection && $order->service) {
            $problem = $this->turnaround->problem($order->service, $order->pickup_date, $order->pickupSlot, $date, $slot, withLatest: false);

            if ($problem !== null) {
                throw new RuntimeException('delivery_'.$problem);
            }
        }

        return DB::transaction(function () use ($order, $task, $slot, $date, $collection) {
            // The same cap as the wizard. Without it a full window is reachable
            // through the back door — postpone, then rebook into it.
            $this->slots->claim($slot->id, $date);

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
            ]);

            // Both legs of the end that moved are due by the new window — the
            // same rule the legs were created with (TaskGenerator::dueFor()), so
            // the partner leg is not left «late» at the old time.
            $this->refreshDue($order->refresh(), $collection
                ? [TaskType::PickupFromCustomer, TaskType::DeliverToLaundry]
                : [TaskType::CollectFromLaundry, TaskType::DeliverToCustomer]);

            // A pickup moved later can leave the delivery too soon after it for
            // the service. The owner's rule: the delivery moves with it, to the
            // first window with room that leaves the service its time.
            if ($collection) {
                $this->keepDeliveryAfterPickup($order->refresh());
            }

            // Offered immediately — but for the new time, which is the difference
            // between this and what used to happen. Left for a person when
            // automatic assignment is off.
            $this->dispatcher->automatically($task->refresh());

            return $order->fresh();
        });
    }

    /**
     * Re-derive the due time of these legs from the order's windows as they now
     * stand. Finished legs are history and keep theirs.
     *
     * @param  array<int, TaskType>  $types
     */
    private function refreshDue(Order $order, array $types): void
    {
        $order->tasks()
            ->whereIn('type', array_map(fn (TaskType $type) => $type->value, $types))
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])
            ->get()
            ->each(fn (OrderTask $leg) => $leg->update(['due_at' => $this->tasks->dueFor($order, $leg->type)]));
    }

    /**
     * Move the delivery out to the first window the service allows, when the
     * pickup has just moved past it. Nothing when it still fits.
     *
     * @throws RuntimeException when no window in the next weeks has room — the
     *                          whole reschedule is then refused, rather than
     *                          leaving a delivery nobody can make
     */
    private function keepDeliveryAfterPickup(Order $order): void
    {
        $service = $order->service;

        if (! $service || ! $order->delivery_date) {
            return;
        }

        // Only when the pickup has pushed past it. A delivery the customer booked
        // further out stays where they put it — the booking window bounds new
        // bookings, it is not a reason to pull an agreed one in.
        $problem = $this->turnaround->problem($service, $order->pickup_date, $order->pickupSlot, $order->delivery_date, $order->deliverySlot, withLatest: false);

        if ($problem !== Turnaround::TOO_EARLY) {
            return;
        }

        // The first window with room — and if somebody takes its last place
        // between the search and the claim, the one after it. A refusal here
        // would name the pickup window the customer chose, which was fine.
        $first = null;

        foreach ($this->turnaround->deliveryWindows($service, $order->pickup_date, $order->pickupSlot) as $window) {
            try {
                $this->slots->claim($window['slot']->id, $window['date']);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'slot_full') {
                    continue;
                }

                throw $e;
            }

            $first = $window;

            break;
        }

        if ($first === null) {
            throw new RuntimeException('no_delivery_after_pickup');
        }

        $order->forceFill([
            'delivery_slot_id' => $first['slot']->id,
            'delivery_date' => $first['date']->toDateString(),
        ])->save();

        $order->refresh();

        // The two legs that bring the pieces back are due by the new window. A
        // driver already holding one planned it for the old day: it is handed
        // back and offered again for the new one — through the automatic path,
        // so with drivers assigned by hand it waits for a person.
        $legs = [TaskType::CollectFromLaundry, TaskType::DeliverToCustomer];

        $released = $order->tasks()
            ->whereIn('type', array_map(fn (TaskType $type) => $type->value, $legs))
            ->where('status', TaskStatus::Assigned->value)
            ->get()
            ->each(fn (OrderTask $leg) => $this->dispatcher->release($leg))
            ->count();

        $this->refreshDue($order, $legs);

        $waiting = $order->tasks()
            ->whereIn('type', array_map(fn (TaskType $type) => $type->value, $legs))
            ->whereNull('driver_id')
            ->where('status', TaskStatus::Pending->value)
            ->get()
            ->each(fn (OrderTask $leg) => $this->dispatcher->automatically($leg->setRelation('order', $order), announce: false))
            ->filter(fn (OrderTask $leg) => $leg->fresh()?->driver_id === null)
            ->count();

        // Legs somebody had assigned by hand are back on the board: with drivers
        // assigned by hand, say so rather than leave them looking covered.
        if ($released > 0 && $waiting > 0 && ! $this->dispatcher->assignsAutomatically() && $order->laundry_id !== null) {
            $this->dispatcher->announceWaiting($order, $waiting);
        }

        $this->machine->note(
            $order,
            __('Delivery moved to :date, :window — the service needs :time after the pickup.', [
                'date' => $first['date']->toDateString(),
                'window' => $first['slot']->label(),
                'time' => $this->turnaround->describe($service),
            ]),
            'customer',
        );
    }
}
