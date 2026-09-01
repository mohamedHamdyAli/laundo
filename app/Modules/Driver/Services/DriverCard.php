<?php

namespace App\Modules\Driver\Services;

use App\Modules\Order\Enums\TaskStatus;
use App\Modules\Order\Enums\TaskType;
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
            'location' => $this->location($task),
        ];
    }

    /**
     * How long a reading stays worth drawing.
     *
     * The app reports every thirty seconds, so four missed reports is a phone
     * that has lost signal, been closed, or run out of battery. Past that the
     * dot is removed rather than left where it was: a stationary marker reads as
     * «السائق واقف» and sends the customer to the phone.
     */
    private const FRESH_FOR_SECONDS = 120;

    /**
     * Where the driver is — but only while they are coming to this customer.
     *
     * Legs two and three run between the laundry and back, and the customer is
     * not waiting at either end of them. Showing a driver's live position for a
     * journey nobody is waiting on is surveillance with no purpose, so the map
     * is limited to the two legs that end at the customer's door.
     *
     * @return array<string, mixed>|null
     */
    private function location(OrderTask $task): ?array
    {
        if (! in_array($task->type, [TaskType::PickupFromCustomer, TaskType::DeliverToCustomer], true)) {
            return null;
        }

        if (! in_array($task->status, [TaskStatus::Assigned, TaskStatus::Started], true)) {
            return null;
        }

        $profile = $task->driver?->profile;

        if (! $profile?->located_at || $profile->last_lat === null || $profile->last_lng === null) {
            return null;
        }

        if ($profile->located_at->lt(now()->subSeconds(self::FRESH_FOR_SECONDS))) {
            return null;
        }

        return [
            'lat' => (float) $profile->last_lat,
            'lng' => (float) $profile->last_lng,
            'updated_at' => $profile->located_at->toIso8601String(),
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
        $tasks = $order->tasks()->with(['driver:id,name,image_profile', 'driver.profile'])->orderBy('sequence')->get();

        return $tasks->first(fn (OrderTask $t) => in_array(
            $t->status,
            [TaskStatus::Assigned, TaskStatus::Started],
            true
        ))
            ?? $tasks->last(fn (OrderTask $t) => $t->status === TaskStatus::Completed && $t->driver_id !== null);
    }
}
