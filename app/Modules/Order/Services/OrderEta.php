<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Support\Carbon;

/**
 * «هيوصل امتى؟» — the question the tracking screen exists to answer.
 *
 * **This is the promised window, not a routed arrival time.** Nothing in this
 * system knows where a van is going next: there is no routing provider, no
 * distance matrix and no queue of stops. An `eta` invented from a driver's
 * current position and a straight line would be a number we cannot keep, on the
 * one screen a customer reads while waiting — and a promise broken by five
 * minutes is worse than a window honoured.
 *
 * So the answer is the window the customer was actually given: the slot booked
 * for the leg they are waiting on, on its date. That is a commitment the
 * business already made and dispatch already works to.
 *
 * `eta_iso` is its **start**, because a client needs a single instant to
 * count down to, and `eta_window` carries both ends so the screen can say
 * «بين ٩ و١٢» rather than pretending to a minute. When the window has already
 * opened, the start is in the past — `eta_minutes` goes to zero rather than
 * negative, which is «any time now» and not «twenty minutes ago».
 */
class OrderEta
{
    /**
     * When the customer should expect the visit they are waiting on.
     *
     * Null when nothing is on its way to them: no live leg, or a leg with no
     * window booked. A null ETA is an honest «we cannot say» and the screen
     * draws nothing; a fabricated one is a complaint.
     *
     * @return array<string, mixed>|null
     */
    public function forOrder(Order $order): ?array
    {
        $task = $this->pendingLeg($order);

        if ($task === null) {
            return null;
        }

        $collection = in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true);

        $date = $collection ? $order->pickup_date : $order->delivery_date;
        $slot = $collection ? $order->pickupSlot : $order->deliverySlot;

        // `due_at` is what dispatch sorts on and is set from the slot when the
        // leg is scheduled. Preferred over rebuilding the instant here, so the
        // customer is told the same time the driver is working to.
        $start = $task->due_at ?? $this->at($date, $slot?->start_time);

        if ($start === null) {
            return null;
        }

        $end = $this->at($date, $slot?->end_time);

        return [
            'iso' => isoDate($start),
            // Never negative: once the window has opened the honest answer is
            // «any moment», not a count of how late it is.
            'minutes' => max(0, (int) ceil(now()->diffInMinutes($start, false))),
            'label' => humanDate($start),
            'window' => $slot === null ? null : [
                'from' => isoDate($start),
                'to' => isoDate($end),
                'label' => $slot->label(),
            ],
            // Said plainly, so a client is not left to infer it from the shape:
            // this is the booked window, not a live arrival estimate.
            'source' => 'time_slot',
        ];
    }

    /**
     * The leg the customer is waiting on, if one is on its way.
     *
     * The same three legs the driver card opens for — a journey to the laundry
     * is not one the customer is standing at either end of — and only while it
     * is still ahead of them. A completed leg has arrived; there is nothing to
     * estimate.
     */
    private function pendingLeg(Order $order): ?OrderTask
    {
        $order->loadMissing('tasks');

        return $order->tasks
            ->sortBy('sequence')
            ->first(fn (OrderTask $task) => in_array($task->type, [
                TaskType::PickupFromCustomer,
                TaskType::CollectFromLaundry,
                TaskType::DeliverToCustomer,
            ], true) && in_array($task->status, [
                TaskStatus::Pending,
                TaskStatus::Assigned,
                TaskStatus::Started,
            ], true));
    }

    /**
     * A date plus a `time` column, as one instant.
     *
     * `time_slots.start_time` carries no date, so it means nothing on its own —
     * and the date is stored UTC like every other timestamp here. Rendering is
     * `humanDate()`'s and `isoDate()`'s job, not this one's.
     */
    private function at(?Carbon $date, ?string $time): ?Carbon
    {
        if ($date === null || ! $time) {
            return null;
        }

        return $date->copy()->setTimeFromTimeString($time);
    }
}
