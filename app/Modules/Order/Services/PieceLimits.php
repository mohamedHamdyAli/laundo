<?php

namespace App\Modules\Order\Services;

use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\TimeSlot\Models\TimeSlot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * «التحكم في عدد القطع» — how many pieces one order may carry, and how many a
 * pickup window may collect (the client's sheet, item 19, 2026-11-08).
 *
 * Two limits, both the platform's, both blank = no limit:
 *
 * - **per order** — the `Max_Pieces_Per_Order` setting;
 * - **per pickup window** — `time_slots.max_pieces`, the pieces of every live
 *   order collected in that window on that day, added up. A delivery in the
 *   same window carries nothing new and is not counted.
 *
 * Over either, the order is refused — and a window with no room left is
 * reported full by `GET /time-slots`, so the app never offers it. Pieces are
 * the basket's (`estimated_items_count`): what the customer said they would
 * hand over, the only number there is when the window is booked.
 */
class PieceLimits
{
    public const SETTING = 'Max_Pieces_Per_Order';

    /**
     * The most pieces one order may carry, or null for no limit.
     */
    public function perOrder(): ?int
    {
        $value = getSettingValue(self::SETTING);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @throws RuntimeException `too_many_pieces`
     */
    public function assertOrderFits(int $pieces): void
    {
        $max = $this->perOrder();

        if ($max !== null && $pieces > $max) {
            throw new RuntimeException('too_many_pieces');
        }
    }

    /**
     * Pieces already booked for collection in this window on this day. A
     * cancelled order gives its pieces back, like its place
     * (`SlotCapacity`); a postponed pickup has no window and holds none.
     */
    public function bookedInWindow(TimeSlot $slot, CarbonInterface|string $date, ?int $exceptOrderId = null): int
    {
        return (int) Order::withoutGlobalScopes()
            ->where('pickup_slot_id', $slot->id)
            ->whereDate('pickup_date', Carbon::parse($date)->toDateString())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->when($exceptOrderId !== null, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->sum('estimated_items_count');
    }

    /**
     * Pieces this window can still collect on this day, or null when it has
     * no limit — null and 0 are different answers, as with places.
     */
    public function roomInWindow(TimeSlot $slot, CarbonInterface|string $date): ?int
    {
        if ($slot->max_pieces === null) {
            return null;
        }

        return max(0, (int) $slot->max_pieces - $this->bookedInWindow($slot, $date));
    }

    /**
     * Take room for `$pieces` in a pickup window, or refuse.
     *
     * Under a lock on the window's row, inside the transaction that books or
     * moves the order — the same reason as `SlotCapacity::claim()`: two
     * customers reading «room for 10» at once must not both take it.
     * `$exceptOrderId` is the order being moved, whose pieces are already
     * counted wherever it is now.
     *
     * @throws RuntimeException `window_pieces_full`
     */
    public function claimWindow(?int $slotId, CarbonInterface|string|null $date, int $pieces, ?int $exceptOrderId = null): void
    {
        if ($slotId === null || $date === null) {
            return;
        }

        $slot = TimeSlot::whereKey($slotId)->lockForUpdate()->first();

        if (! $slot || $slot->max_pieces === null) {
            return;
        }

        if ($this->bookedInWindow($slot, $date, $exceptOrderId) + max(0, $pieces) > (int) $slot->max_pieces) {
            throw new RuntimeException('window_pieces_full');
        }
    }
}
