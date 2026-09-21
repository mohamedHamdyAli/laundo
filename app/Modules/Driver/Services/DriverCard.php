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
 * task on one of the **three** legs the customer is waiting on — and it
 * disappears the moment that leg finishes. A driver's number is reachable while
 * they are handling this customer's clothes, not for ever.
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
            // The same URL under the name the app reads it by.
            'photo' => getImageassetUrl($driver->image_profile),
            // «مندوب الاستلام» / «مندوب التسليم» — which of the four legs this is
            // matters to the customer: the person collecting and the person
            // returning their clothes are usually not the same.
            'role' => __($task->type->label()),
            // **The same fact as a key, not as prose.** `role` is translated, so
            // a client switching on it is switching on the request's language —
            // and this is the field that decides whether the screen says
            // «جاي ياخد» or «جاي يسلّم». `leg` is the customer's two sides;
            // `task_type` is the raw leg, for anything that needs all four.
            'leg' => $this->side($task),
            'task_type' => $task->type->value,
            'rating' => $this->rating((int) $driver->id),
            // Same gate as the location below, deliberately: the two answer the
            // same question — «is this person on their way to me right now» —
            // and letting them disagree would leave a number reachable after
            // the dot had gone.
            'phone' => $this->reachableWhileLive($task) ? $driver->phone : null,
            'location' => $this->location($task),
            // The last thing the server knows, however old it is — and the age,
            // so the client can say how old. `location` is the recommendation
            // («fresh enough to draw as live»); this is the fact behind it.
            //
            // Both exist because `location: null` answered two different
            // questions with the same word: «this driver has never reported»
            // and «this driver reported four minutes ago». The customer's screen
            // could only ever say «موقع المندوب غير متاح», and the map went
            // blank the moment the driver locked their phone — then stayed blank
            // when they unlocked it, until a new reading landed.
            'last_seen' => $this->lastSeen($task),
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
     * Whether this leg is one the customer is currently waiting on.
     *
     * **Three of the four legs, not two.** It was two — the ones that end at the
     * customer's door — on the reasoning that a journey nobody is waiting at
     * either end of does not justify a live map.
     *
     * That reasoning held for leg two, the run from the customer's door to the
     * laundry, and it was wrong for leg three. Both return journeys are assigned
     * to a driver **at the same moment**, the instant the laundry marks an order
     * ready. So the card reported «collecting from the laundry» with no number
     * and no map for the whole way back, and the customer standing at home saw a
     * screen that had not moved since the clothes were washed. The journey they
     * are most obviously waiting on was the one the card went quiet for.
     *
     * Leg two stays out. That one really is none of the customer's business:
     * their clothes are going away from them, and there is nothing they would
     * ring a driver about.
     */
    private function reachableWhileLive(OrderTask $task): bool
    {
        return in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::CollectFromLaundry,
            TaskType::DeliverToCustomer,
        ], true)
            && in_array($task->status, [TaskStatus::Assigned, TaskStatus::Started], true);
    }

    /**
     * Which side of the order this leg is, in the customer's terms.
     *
     * Four legs collapse to two sides because the customer only ever stands at
     * one end: either their clothes are being taken away or they are coming
     * back. The middle two belong to the laundry, and `deliver_to_laundry` is
     * reported as `pickup` because it is the tail of the journey that started
     * at the customer's door.
     */
    private function side(OrderTask $task): string
    {
        return in_array($task->type, [
            TaskType::PickupFromCustomer,
            TaskType::DeliverToLaundry,
        ], true) ? 'pickup' : 'delivery';
    }

    /**
     * Where the driver is — but only while they are handling this order for the
     * customer, and only while the reading is fresh.
     *
     * @return array<string, mixed>|null
     */
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
     * The last stored reading, at whatever age, while the leg is still live.
     *
     * Gated exactly as `location()` is, and that is the whole of the privacy
     * story: the same three legs, the same live statuses. What is relaxed here
     * is freshness alone — a position the customer was already entitled to see
     * does not become secret because it is four minutes old. It becomes *stale*,
     * which is a different thing, and `age_seconds` is how the client is told
     * which one it is holding.
     *
     * `is_stale` is the server's own answer against `FRESH_FOR_SECONDS`, so a
     * client that does not want to hardcode the threshold does not have to —
     * and so the two halves of this payload cannot drift apart.
     *
     * @return array<string, mixed>|null
     */
    private function lastSeen(OrderTask $task): ?array
    {
        if (! $this->reachableWhileLive($task)) {
            return null;
        }

        $profile = $task->driver?->profile;

        if (! $profile?->located_at || $profile->last_lat === null || $profile->last_lng === null) {
            return null;
        }

        $age = (int) $profile->located_at->diffInSeconds(now());

        return [
            'lat' => (float) $profile->last_lat,
            'lng' => (float) $profile->last_lng,
            'at_iso' => $profile->located_at->toIso8601String(),
            'age_seconds' => $age,
            'is_stale' => $age > self::FRESH_FOR_SECONDS,
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
