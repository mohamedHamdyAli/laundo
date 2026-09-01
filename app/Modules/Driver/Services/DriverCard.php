<?php

namespace App\Modules\Driver\Services;

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderRating;
use App\Modules\Order\Models\OrderTask;
use Illuminate\Support\Facades\DB;

/**
 * «مندوب الاستلام · أحمد · ★ 4.9» — the driver, as the customer sees them.
 *
 * The tracking screen has carried this card in the design since the first
 * version and the endpoint behind it returned no driver at all: a customer
 * waiting at home could see that a driver was on the way and not who.
 *
 * Deliberately **no phone number.** Handing a driver's personal mobile to every
 * customer they collect from is a policy decision, not a field, and the design
 * shows no call button. Operations has the number; the customer has support.
 */
class DriverCard
{
    /**
     * The driver the customer is currently waiting on, if any.
     *
     * @return array<string, mixed>|null
     */
    public function forOrder(Order $order): ?array
    {
        $task = $this->liveTask($order);
        $driver = $task?->driver;

        if (! $driver) {
            return null;
        }

        return [
            'name' => $driver->name,
            'image' => getImageassetUrl($driver->image_profile),
            // «مندوب الاستلام» / «مندوب التسليم» — which of the four legs this is
            // matters to the customer: the person collecting and the person
            // returning their clothes are usually not the same.
            'role' => __($task->type->label()),
            'rating' => $this->rating((int) $driver->id),
        ];
    }

    /**
     * The average of what customers said about **delivery**, not about the wash.
     *
     * `order_ratings` keeps four separate columns precisely so this is possible:
     * «التوصيل والاستلام» describes the driver and «جودة الخدمة» describes the
     * laundry, and averaging them together would mark a driver down for a badly
     * ironed shirt.
     *
     * Null until somebody has actually said something. A new driver shown as 0.0
     * reads as a bad driver rather than an unrated one.
     */
    public function rating(int $driverId): ?float
    {
        $average = OrderRating::query()
            ->whereNotNull('delivery')
            ->whereIn('order_id', DB::table('order_tasks')
                ->select('order_id')
                ->where('driver_id', $driverId))
            ->avg('delivery');

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * The leg in flight: the earliest one somebody is holding.
     *
     * Falls back to the last completed leg, so an order sitting at the laundry
     * still names the person who collected it rather than going blank between
     * journeys.
     */
    private function liveTask(Order $order): ?OrderTask
    {
        $tasks = $order->tasks()->with('driver:id,name,image_profile')->orderBy('sequence')->get();

        return $tasks->first(fn (OrderTask $t) => in_array(
            $t->status,
            [TaskStatus::Assigned, TaskStatus::Started],
            true
        ))
            ?? $tasks->last(fn (OrderTask $t) => $t->status === TaskStatus::Completed && $t->driver_id !== null);
    }
}
