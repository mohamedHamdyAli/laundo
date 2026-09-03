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
 * **The phone number is here, and only while the leg is live.** It was withheld
 * outright, on the reasoning that handing a driver's personal mobile to every
 * customer they collect from is a policy decision rather than a field — and
 * that the design showed no call button. The design has since grown one, and
 * the decision was revisited: a customer with a driver outside their building
 * and no way to say «I'm on the third floor» is the case the withholding was
 * costing.
 *
 * So it is gated exactly as the live location is — an `Assigned` or `Started`
 * task on one of the two legs that end at the customer — and it disappears the
 * moment that leg finishes. A driver's number is reachable for the twenty
 * minutes they are at somebody's door, not for ever.
 *
 * A masked proxy number would be better than either and needs a telephony
 * provider; none is wired yet, and neither is SMS.
 *
 * There is still no chat: nothing in the system carries a message between a
 * customer and a driver, and the button for it comes off the design rather than
 * pointing at nothing.
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
            // Same gate as the location below, deliberately: the two answer the
            // same question — «is this person on their way to me right now» —
            // and letting them disagree would leave a number reachable after
            // the dot had gone.
            'phone' => $this->reachableWhileLive($task) ? $driver->phone : null,
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
    /**
     * Whether this leg is one the customer is currently waiting on.
     *
     * The two legs between the laundry and us are none of the customer's
     * business — they are not waiting at the laundry's door — and a task that
     * is finished or not yet assigned is not «right now».
     */
    private function reachableWhileLive(OrderTask $task): bool
    {
        return in_array($task->type, [TaskType::PickupFromCustomer, TaskType::DeliverToCustomer], true)
            && in_array($task->status, [TaskStatus::Assigned, TaskStatus::Started], true);
    }

    private function location(OrderTask $task): ?array
    {
        if (! $this->reachableWhileLive($task)) {
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
        $tasks = $order->tasks()
            // `phone` is in the column list because the card now carries it
            // while a leg is live. A constrained eager load silently returns
            // null for anything left out, so the number would have read as
            // «withheld» rather than as a mistake.
            ->with(['driver:id,name,phone,image_profile', 'driver.profile'])
            ->orderBy('sequence')
            ->get();

        return $tasks->first(fn (OrderTask $t) => in_array(
            $t->status,
            [TaskStatus::Assigned, TaskStatus::Started],
            true
        ))
            ?? $tasks->last(fn (OrderTask $t) => $t->status === TaskStatus::Completed && $t->driver_id !== null);
    }
}
