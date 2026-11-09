<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The last booking is now measured from a window's **start**, a quarter of an
 * hour before it (the client's sheet, item 8, 2026-11-08: «آخر وقت لعمل أوردر
 * يكون قبل بداية كل فترة بـ 15 دقيقة»).
 *
 * `Slot_Booking_Cutoff_Minutes` meant «before the window ends» until today, so
 * whatever it held (blank meant an hour) means nothing under the new rule — a
 * 60 kept as it is would quietly close every window an hour before it opens.
 * It is set to the client's figure; the settings screen changes it as before.
 * `down()` puts back the value it found.
 */
return new class extends Migration
{
    private const KEY = 'Slot_Booking_Cutoff_Minutes';

    private const BEFORE = 'Slot_Booking_Cutoff_Minutes_before_2026_11_08';

    public function up(): void
    {
        DB::transaction(function () {
            $row = DB::table('settings')->where('key', self::KEY)->first();

            // Kept beside it, so `down()` restores exactly what was there.
            DB::table('settings')->updateOrInsert(
                ['key' => self::BEFORE],
                ['value' => $row?->value, 'created_at' => now(), 'updated_at' => now()],
            );

            DB::table('settings')->updateOrInsert(
                ['key' => self::KEY],
                ['value' => '15', 'updated_at' => now()] + ($row ? [] : ['created_at' => now()]),
            );
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $before = DB::table('settings')->where('key', self::BEFORE)->first();

            if ($before) {
                DB::table('settings')->where('key', self::KEY)->update(['value' => $before->value, 'updated_at' => now()]);
                DB::table('settings')->where('key', self::BEFORE)->delete();
            }
        });
    }
};
