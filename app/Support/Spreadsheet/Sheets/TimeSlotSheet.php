<?php

namespace App\Support\Spreadsheet\Sheets;

use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\TimeSlot\Services\SlotCapacity;
use App\Support\Spreadsheet\Column;
use App\Support\Spreadsheet\Sheet;
use Illuminate\Database\Eloquent\Builder;

/**
 * The delivery windows — export only.
 *
 * No search: the screen's filter box is client-side (`setupClientFilter`) and
 * matches the rendered label, which no column holds, so the export is every
 * window. The booked counts are the same two the list shows, read the same way.
 */
class TimeSlotSheet extends Sheet
{
    public function key(): string
    {
        return 'time_slot';
    }

    public function title(): string
    {
        return 'time-slots';
    }

    public function query(): Builder
    {
        return TimeSlot::query();
    }

    public function columns(): array
    {
        return [
            Column::make('id'),
            Column::readOnly('window', fn (TimeSlot $slot) => $slot->label()),
            Column::make('start_time'),
            Column::make('end_time'),
            Column::make('applies_to'),
            Column::make('capacity'),
            Column::readOnly('booked_today', fn (TimeSlot $slot) => app(SlotCapacity::class)->booked($slot, now())),
            Column::readOnly('booked_tomorrow', fn (TimeSlot $slot) => app(SlotCapacity::class)->booked($slot, now()->addDay())),
            Column::make('sort_order'),
            Column::make('status'),
        ];
    }
}
