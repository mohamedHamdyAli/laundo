<?php

namespace App\Modules\TimeSlot\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\TimeSlot\Models\TimeSlot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * How many visits a window can still take.
 *
 * `time_slots.capacity` has been editable in the dashboard since P4, returned by
 * the API, and enforced nowhere — so a window could not fill up however many
 * people chose it. Fifty customers could all pick «3–6 مساءً» and nothing said no.
 *
 * **A booking is a visit, not an order.** A pickup and a delivery in the same
 * window are two separate journeys to two separate doors, so they both count.
 * Counting orders instead would let a day quietly carry twice the traffic it was
 * configured for.
 *
 * **Per window, per day, across the platform.** That is what the column can say:
 * it carries no city and no laundry. Capacity that varies by city or by laundry
 * is a different column and a different conversation.
 */
class SlotCapacity
{
    /**
     * Orders that no longer need anybody to travel.
     *
     * A cancelled order must give its place back — holding it would let one
     * abandoned booking block a window for the rest of the day.
     */
    private const RELEASED = [
        OrderStatus::Cancelled->value,
    ];

    /**
     * Visits already booked into this window on this day.
     */
    public function booked(TimeSlot $slot, CarbonInterface|string $date): int
    {
        $day = Carbon::parse($date)->toDateString();

        $pickups = Order::withoutGlobalScopes()
            ->where('pickup_slot_id', $slot->id)
            ->whereDate('pickup_date', $day)
            ->whereNotIn('status', self::RELEASED)
            ->count();

        $deliveries = Order::withoutGlobalScopes()
            ->where('delivery_slot_id', $slot->id)
            ->whereDate('delivery_date', $day)
            ->whereNotIn('status', self::RELEASED)
            ->count();

        return $pickups + $deliveries;
    }

    /**
     * Places left, or null when the window is uncapped.
     *
     * Null and 0 are different answers and the app must draw them differently:
     * null is «as many as you like», 0 is «choose another window».
     */
    public function remaining(TimeSlot $slot, CarbonInterface|string $date): ?int
    {
        if ($slot->capacity === null) {
            return null;
        }

        return max(0, (int) $slot->capacity - $this->booked($slot, $date));
    }

    public function isFull(TimeSlot $slot, CarbonInterface|string $date): bool
    {
        $remaining = $this->remaining($slot, $date);

        return $remaining !== null && $remaining < 1;
    }

    /**
     * Take a place, or refuse.
     *
     * `lockForUpdate` on the slot row is what makes the count trustworthy: two
     * customers submitting into the last place at the same instant would both
     * read “one left” and both be allowed. The lock is on the window rather than
     * on the orders table, so bookings into *different* windows never wait for
     * each other.
     *
     * Call inside the transaction that creates or moves the order — a check that
     * finishes before the write is a check somebody can slip past.
     *
     * @throws RuntimeException
     */
    public function claim(?int $slotId, CarbonInterface|string|null $date): void
    {
        if ($slotId === null || $date === null) {
            // An order with no date cannot consume a dated window. Scheduling is
            // optional in this API and that is not the capacity code's business.
            return;
        }

        $slot = TimeSlot::whereKey($slotId)->lockForUpdate()->first();

        if (! $slot || $slot->capacity === null) {
            return;
        }

        if ($this->booked($slot, $date) >= (int) $slot->capacity) {
            throw new RuntimeException('slot_full');
        }
    }
}
