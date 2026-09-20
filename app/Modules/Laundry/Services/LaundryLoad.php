<?php

namespace App\Modules\Laundry\Services;

use App\Modules\Laundry\Models\LaundrySlotCapacity;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * How much work a laundry already has in a given window.
 *
 * **Intake, not visits.** An order counts against the laundry that will wash it,
 * in the window its clothes are *collected*. The delivery leg is clothes going
 * back out and is not work in the window it happens in — so, unlike the
 * platform-wide `SlotCapacity`, which counts both legs because it is really
 * counting journeys by van, this counts each order once.
 *
 * **It never refuses anything.** Nothing here throws and nothing claims a place.
 * A full laundry is an input to `LaundryAssigner`'s ranking, and what happens
 * when every laundry in a zone is full is a decision the operator makes on the
 * settings screen — not one this class gets to take by raising an exception
 * inside somebody's checkout.
 */
class LaundryLoad
{
    /**
     * Orders that have given their place back.
     *
     * Mirrors `SlotCapacity::RELEASED`. A cancelled order frees the machine; a
     * returned one does not, because the clothes were washed.
     */
    private const RELEASED = [
        OrderStatus::Cancelled->value,
    ];

    /**
     * Orders already booked into this laundry for this window on this day.
     */
    public function booked(int $laundryId, ?int $slotId, CarbonInterface|string|null $date): int
    {
        if ($slotId === null || $date === null) {
            // Scheduling is optional in the API. An order with no window cannot
            // consume one, and counting it against every window would make a
            // laundry look full on a day nothing was booked.
            return 0;
        }

        return Order::withoutGlobalScopes()
            ->where('laundry_id', $laundryId)
            ->where('pickup_slot_id', $slotId)
            ->whereDate('pickup_date', Carbon::parse($date)->toDateString())
            ->whereNotIn('status', self::RELEASED)
            ->count();
    }

    /**
     * The ceiling this laundry set for this window, or null for uncapped.
     */
    public function capacity(int $laundryId, ?int $slotId): ?int
    {
        if ($slotId === null) {
            return null;
        }

        $row = LaundrySlotCapacity::withoutGlobalScopes()
            ->where('laundry_id', $laundryId)
            ->where('time_slot_id', $slotId)
            ->first();

        return $row?->capacity;
    }

    /**
     * Places left, or null when the laundry is uncapped for this window.
     *
     * Null and 0 are different answers and the ranking treats them differently:
     * null is «as much as you like», 0 is «this one is full».
     */
    public function remaining(int $laundryId, ?int $slotId, CarbonInterface|string|null $date): ?int
    {
        $capacity = $this->capacity($laundryId, $slotId);

        if ($capacity === null) {
            return null;
        }

        return max(0, $capacity - $this->booked($laundryId, $slotId, $date));
    }

    /**
     * The number the balancing rule sorts on: how many more orders this laundry
     * can take before it is full.
     *
     * An uncapped laundry returns `PHP_INT_MAX`, which is the literal truth and
     * makes it win every tie — correct, because a laundry that has told us no
     * ceiling has not told us it is busy.
     *
     * This is «free places» rather than «fewest orders» by the owner's decision:
     * a laundry holding four of twenty has more room than one holding none of
     * two, and sending the order to the empty two-slot laundry would fill it and
     * leave sixteen machines idle next door.
     */
    public function freePlaces(int $laundryId, ?int $slotId, CarbonInterface|string|null $date): int
    {
        return $this->remaining($laundryId, $slotId, $date) ?? PHP_INT_MAX;
    }

    /**
     * Is every laundry covering this zone already full for this window?
     *
     * What the slot list asks so the customer can be spared a window that has
     * nowhere to go. **False when no laundry covers the zone at all** — that is
     * a coverage gap, not a full day, and it is already handled by accepting
     * the order unassigned. Hiding the window there would tell a customer in an
     * uncovered area that we are simply busy.
     *
     * @param  array<int, int>  $laundryIds
     */
    public function everyOneFull(array $laundryIds, ?int $slotId, CarbonInterface|string|null $date): bool
    {
        if ($laundryIds === [] || $slotId === null || $date === null) {
            return false;
        }

        foreach ($this->summaryFor($laundryIds, $slotId, $date) as $summary) {
            if (! $summary['full']) {
                return false;
            }
        }

        return true;
    }

    public function isFull(int $laundryId, ?int $slotId, CarbonInterface|string|null $date): bool
    {
        $remaining = $this->remaining($laundryId, $slotId, $date);

        return $remaining !== null && $remaining < 1;
    }

    /**
     * Booked and capacity for several laundries at once, for a screen that shows
     * a row per candidate.
     *
     * @param  array<int, int>  $laundryIds
     * @return array<int, array{booked: int, capacity: int|null, remaining: int|null, full: bool}>
     */
    public function summaryFor(array $laundryIds, ?int $slotId, CarbonInterface|string|null $date): array
    {
        if ($laundryIds === [] || $slotId === null || $date === null) {
            return array_fill_keys($laundryIds, [
                'booked' => 0,
                'capacity' => null,
                'remaining' => null,
                'full' => false,
            ]);
        }

        $day = Carbon::parse($date)->toDateString();

        // One grouped count rather than one query per laundry: this runs on a
        // screen that draws every candidate in a zone.
        $booked = Order::withoutGlobalScopes()
            ->whereIn('laundry_id', $laundryIds)
            ->where('pickup_slot_id', $slotId)
            ->whereDate('pickup_date', $day)
            ->whereNotIn('status', self::RELEASED)
            ->selectRaw('laundry_id, COUNT(*) as aggregate')
            ->groupBy('laundry_id')
            ->pluck('aggregate', 'laundry_id');

        $capacities = LaundrySlotCapacity::withoutGlobalScopes()
            ->whereIn('laundry_id', $laundryIds)
            ->where('time_slot_id', $slotId)
            ->pluck('capacity', 'laundry_id');

        $out = [];

        foreach ($laundryIds as $id) {
            $capacity = $capacities[$id] ?? null;
            $used = (int) ($booked[$id] ?? 0);
            $remaining = $capacity === null ? null : max(0, (int) $capacity - $used);

            $out[$id] = [
                'booked' => $used,
                'capacity' => $capacity === null ? null : (int) $capacity,
                'remaining' => $remaining,
                'full' => $remaining !== null && $remaining < 1,
            ];
        }

        return $out;
    }
}
