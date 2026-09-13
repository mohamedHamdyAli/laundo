<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «وصّل ١٠٠ طلب الشهر ده → ٥٠٠ ج».
 *
 * A rule's monthly targets, as rows rather than as three columns on the rule.
 * Two reasons, and the second is the one that matters: an operator wanting a
 * fourth tier should add a row, not wait for a migration — and a tier list of
 * unknown length cannot be modelled as columns without picking a maximum
 * somebody will eventually need to exceed.
 *
 * **The highest tier reached wins, not the sum.** A driver who delivered 160
 * against tiers at 100 and 150 is paid the 150 tier once, not both. That is what
 * an operator means by «١٥٠ طلب → ٩٠٠ ج»: it is the price of the level, not an
 * increment on top of the level below.
 *
 * `unique(rule, min_orders)` because two tiers at the same threshold are two
 * different answers to one question, and whichever the database returned first
 * would silently become the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bonus_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('driver_bonus_rule_id')
                ->constrained('driver_bonus_rules')
                ->cascadeOnDelete();

            // Orders delivered in the month, not legs: a target counted in legs
            // is a target four times easier than it reads.
            $table->unsignedInteger('min_orders');

            $table->decimal('amount', 10, 2);

            $table->timestamps();

            $table->unique(['driver_bonus_rule_id', 'min_orders'], 'bonus_tier_unique_threshold');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bonus_tiers');
    }
};
