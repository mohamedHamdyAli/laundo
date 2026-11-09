<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «التحكم في عدد القطع» — the client's sheet, item 19 (2026-11-08).
 *
 * `time_slots.max_pieces`: how many pieces a pickup window may collect in one
 * day, every live order collected in it added up. Null — every existing
 * window — is no limit, so nothing changes until somebody sets one.
 *
 * `Max_Pieces_Per_Order`: a settings row, blank, so the settings screen opens
 * on it; blank is no limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->unsignedInteger('max_pieces')->nullable()->after('capacity');
        });

        if (! DB::table('settings')->where('key', 'Max_Pieces_Per_Order')->exists()) {
            DB::table('settings')->insert([
                'key' => 'Max_Pieces_Per_Order',
                'value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->dropColumn('max_pieces');
        });

        DB::table('settings')->where('key', 'Max_Pieces_Per_Order')->whereNull('value')->delete();
    }
};
