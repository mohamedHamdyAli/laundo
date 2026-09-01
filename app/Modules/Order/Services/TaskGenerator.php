<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns an order into its four journeys.
 *
 * All four are created **together**, not one at a time as the previous finishes.
 * A driver needs to see tomorrow's delivery and operations need to see the whole
 * chain before either has happened; a chain that materialises leg by leg is
 * invisible until it is urgent.
 *
 * Ordering safety does not come from withholding the rows — it comes from
 * `sequence` and `OrderTask::predecessorComplete()`, which is checked whenever a
 * driver tries to act. The tasks exist; only three of them are not yet doable.
 */
class TaskGenerator
{
    public function __construct(private readonly DriverDispatcher $dispatcher) {}

    /**
     * Create the chain, once.
     *
     * Idempotent by construction: the unique key on (order_id, type) means a
     * second call cannot double-book a doorstep, and the early return means it
     * does not try.
     *
     * @return array<int, OrderTask>
     */
    public function generate(Order $order): array
    {
        if (OrderTask::where('order_id', $order->id)->exists()) {
            return OrderTask::where('order_id', $order->id)->orderBy('sequence')->get()->all();
        }

        return DB::transaction(function () use ($order) {
            $tasks = [];

            foreach (TaskType::chain() as $type) {
                $task = OrderTask::create([
                    'order_id' => $order->id,
                    'type' => $type,
                    'sequence' => $type->sequence(),
                    'status' => TaskStatus::Pending,
                    'due_at' => $this->dueAt($order, $type),
                ]);

                // Dispatched immediately so the queue only ever holds what nobody
                // could take, rather than everything nobody has looked at.
                $this->dispatcher->dispatch($task);

                $tasks[] = $task->refresh();
            }

            return $tasks;
        });
    }

    /**
     * Close the legs of an order that is no longer going anywhere.
     *
     * Cancelling an order without this would leave four tasks in drivers' lists
     * for a journey nobody wants — and the first anyone would learn of it is a
     * driver at a door.
     */
    public function cancelOpenTasks(Order $order): int
    {
        return OrderTask::where('order_id', $order->id)
            ->open()
            ->update([
                'status' => TaskStatus::Failed->value,
                'driver_id' => null,
                'failure_reason' => null,
                'failure_note' => __('Order cancelled.'),
            ]);
    }

    /**
     * When each leg is due.
     *
     * Anchored on the order's own windows, because «متأخرة» in the driver app has
     * no other meaning than the customer's promised time having passed. The two
     * laundry legs sit between the customer's two windows: the hand-over on the
     * pickup day, the collection on the delivery day. Precise scheduling of the
     * laundry's turnaround is not modelled — the laundry sets its own pace, and
     * inventing a deadline for it would create lateness that nobody agreed to.
     */
    private function dueAt(Order $order, TaskType $type): ?Carbon
    {
        $pickup = $this->windowEnd($order->pickup_date, $order->pickupSlot?->end_time);
        $delivery = $this->windowEnd($order->delivery_date, $order->deliverySlot?->end_time);

        return match ($type) {
            TaskType::PickupFromCustomer => $pickup,
            TaskType::DeliverToLaundry => $pickup?->copy()->addHours(2),
            TaskType::CollectFromLaundry => $delivery?->copy()->subHours(2),
            TaskType::DeliverToCustomer => $delivery,
        };
    }

    private function windowEnd($date, ?string $endTime): ?Carbon
    {
        if (! $date) {
            return null;
        }

        $day = Carbon::parse($date);

        if (! $endTime) {
            return $day->endOfDay();
        }

        [$h, $m] = array_pad(explode(':', $endTime), 2, '00');

        return $day->copy()->setTime((int) $h, (int) $m);
    }
}
