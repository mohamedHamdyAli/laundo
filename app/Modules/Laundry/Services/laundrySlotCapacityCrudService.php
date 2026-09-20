<?php

namespace App\Modules\Laundry\Services;

use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Models\LaundrySlotCapacity;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Support\LaundryContext;
use Illuminate\Support\Facades\DB;

/**
 * How many orders each laundry takes in, per window.
 *
 * Mirrors `laundryZoneCrudService`: a grid posted whole, written in one
 * transaction, and confined to the actor's own laundry when the actor is a
 * tenant. Two screens share it — the capacity grid, which edits every laundry
 * at once, and the tab on a laundry's own edit screen, which edits one. They
 * post the same shape and the second is simply the first with a single row, so
 * there is one write path and not two that can drift.
 *
 * **A blank cell deletes the row.** Uncapped is the absence of a number, not a
 * number, and the alternative — keeping a row with a null capacity — leaves the
 * table carrying one row per laundry per window forever, saying nothing.
 */
class laundrySlotCapacityCrudService
{
    public function __construct(private readonly LaundryLoad $load) {}

    /**
     * @return array<string, mixed>
     */
    public function shredData($laundryId = null): array
    {
        $tenantId = LaundryContext::currentId();

        $laundries = Laundry::where('status', 'active')
            ->when($tenantId !== null, fn ($q) => $q->where('id', $tenantId))
            ->orderBy('id')
            ->get();

        $slots = TimeSlot::where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get()
            // Intake only. A delivery-only window is not a window this laundry
            // receives work in, so offering a capacity box for it would invite
            // a number that nothing ever reads.
            ->filter(fn (TimeSlot $slot) => $slot->appliesTo('pickup'))
            ->values();

        $selectedId = $tenantId
            ?? ($laundryId ? (int) $laundryId : $laundries->first()?->id);

        // [laundry_id][time_slot_id] => capacity. Read without scopes so the
        // grid a super admin sees is every laundry, not the empty set a null
        // context would otherwise be indistinguishable from.
        $matrix = [];

        foreach (LaundrySlotCapacity::withoutGlobalScopes()
            ->whereIn('laundry_id', $laundries->pluck('id'))
            ->get() as $row) {
            $matrix[$row->laundry_id][$row->time_slot_id] = $row->capacity;
        }

        return [
            'laundries' => $laundries,
            'slots' => $slots,
            'matrix' => $matrix,
            'selectedLaundryId' => $selectedId,
            'rows' => $matrix,
        ];
    }

    /**
     * Write the posted grid.
     *
     * Only the laundries named in the payload are touched, which is what lets
     * the single-laundry tab save without wiping every other laundry's numbers.
     *
     * Typed as loosely as it arrives. This is a request payload — the shape
     * is what validation asserts, not what the caller promises — so the
     * `is_array` below is a real guard and not decoration.
     *
     * @param  array<int|string, mixed>  $capacities  [laundry_id][slot_id] => value
     * @return int how many cells now hold a number
     */
    public function sync(array $capacities): int
    {
        $tenantId = LaundryContext::currentId();

        // A tenant writes its own row whatever the payload claims — the same
        // rule as every other laundry-scoped write in the panel.
        if ($tenantId !== null) {
            $capacities = array_intersect_key($capacities, [$tenantId => true]);
        }

        $laundryIds = Laundry::whereIn('id', array_map('intval', array_keys($capacities)))
            ->pluck('id')
            ->all();

        $slotIds = TimeSlot::pluck('id')->all();
        $written = 0;

        DB::transaction(function () use ($capacities, $laundryIds, $slotIds, &$written) {
            foreach ($capacities as $laundryId => $cells) {
                $laundryId = (int) $laundryId;

                if (! in_array($laundryId, $laundryIds, true) || ! is_array($cells)) {
                    continue;
                }

                foreach ($cells as $slotId => $value) {
                    $slotId = (int) $slotId;

                    if (! in_array($slotId, $slotIds, true)) {
                        continue;
                    }

                    // Blank means uncapped, and uncapped is no row at all.
                    // `'0'` is not blank: it is «closed for this window», which
                    // a laundry has to be able to say.
                    if ($value === null || $value === '') {
                        LaundrySlotCapacity::withoutGlobalScopes()
                            ->where('laundry_id', $laundryId)
                            ->where('time_slot_id', $slotId)
                            ->delete();

                        continue;
                    }

                    LaundrySlotCapacity::withoutGlobalScopes()->updateOrCreate(
                        ['laundry_id' => $laundryId, 'time_slot_id' => $slotId],
                        ['capacity' => max(0, (int) $value)],
                    );

                    $written++;
                }
            }
        });

        return $written;
    }

    /**
     * What each cell of the grid is currently carrying, for today.
     *
     * Shown beside the ceiling so a number somebody types can be compared with
     * the traffic it is meant to describe — a capacity set without ever seeing
     * the load is a guess.
     *
     * @param  array<int, int>  $laundryIds
     * @return array<int, array<int, int>> [laundry_id][slot_id] => booked
     */
    public function bookedToday(array $laundryIds, array $slotIds): array
    {
        $out = [];

        foreach ($slotIds as $slotId) {
            foreach ($this->load->summaryFor($laundryIds, $slotId, now()) as $laundryId => $summary) {
                $out[$laundryId][$slotId] = $summary['booked'];
            }
        }

        return $out;
    }
}
