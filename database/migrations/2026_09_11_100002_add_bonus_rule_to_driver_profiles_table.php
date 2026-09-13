<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bonus terms this driver is on.
 *
 * **Null means no bonus**, and that is the default for every existing row. It
 * replaces `EarningService::DEFAULT_RATE = 0.20` — a hardcoded share of the
 * delivery fee that was paid to every driver on the platform, read from a
 * setting that has no field on the settings form and no seeder row, so nobody
 * could see it and nobody could stop it.
 *
 * Nullable rather than defaulted to a «standard» rule for the same reason
 * `Commission_Rate` is seeded at zero: a migration that hands three hundred
 * drivers a bonus nobody agreed to is a migration that quietly starts spending
 * money.
 *
 * `nullOnDelete`, not cascade — deleting a rule must not delete drivers. They
 * fall back to no bonus, which is visible on their row rather than silent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreignId('bonus_rule_id')
                ->nullable()
                ->after('max_concurrent_orders')
                ->constrained('driver_bonus_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropForeign(['bonus_rule_id']);
            $table->dropColumn('bonus_rule_id');
        });
    }
};
