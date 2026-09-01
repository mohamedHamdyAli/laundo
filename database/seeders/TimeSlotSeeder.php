<?php

namespace Database\Seeders;

use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The pickup / delivery windows.
 *
 * Lengths follow the design, which mixes three-hour windows
 * ("02:00 مساءً – 05:00 مساءً") with two-hour ones ("5:00 م - 7:00 م").
 *
 * One set serves both pickup and delivery (`applies_to = both`) and applies to
 * every day, per the business decision. Capacity is left null — unlimited —
 * until real throughput is known.
 *
 * Idempotent: matched on the (start_time, end_time) pair, which is a plain
 * column comparison and so avoids the JSON-normalization trap entirely.
 *
 *     php artisan db:seed --class=TimeSlotSeeder
 */
class TimeSlotSeeder extends Seeder
{
    public function run(): void
    {
        $windows = [
            ['09:00', '12:00'],
            ['12:00', '15:00'],
            ['15:00', '18:00'],
            ['18:00', '21:00'],
            // The design shows a late window ("09:00 مساءً إلى 12:00 منتصف الليل"),
            // so the day does not stop at nine.
            ['21:00', '23:59'],
        ];

        DB::transaction(function () use ($windows) {
            $order = 1;

            foreach ($windows as [$start, $end]) {
                $slot = TimeSlot::where('start_time', $start.':00')
                    ->where('end_time', $end.':00')
                    ->first() ?? new TimeSlot;

                $slot->fill([
                    'start_time' => $start,
                    'end_time' => $end,
                    'applies_to' => 'both',
                    'capacity' => null,
                    'sort_order' => $order++,
                    'status' => 'active',
                ])->save();
            }
        });

        $this->command?->info('Time slots seeded: '.TimeSlot::count()
            .' windows, all days, pickup and delivery, unlimited capacity.');
    }
}
