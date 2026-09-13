<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this laundry pays the platform.
 *
 * **Null means the general rate**, not zero. The distinction is the whole point
 * of the column: almost every laundry is on the standard commission and should
 * follow it when it moves, while the one that negotiated 12% must keep 12% when
 * the general rate goes to 15%. A default of 0 would have made "not set" and
 * "free of charge" the same row, and the second is a decision somebody has to
 * make deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('laundries', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};
