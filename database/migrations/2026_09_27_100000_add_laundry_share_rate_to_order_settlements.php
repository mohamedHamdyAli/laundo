<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The share of the washing the laundry was paid at.
 *
 * Until now the percentage on a laundry was what the *platform* took and the
 * laundry had the rest. The client turned it round — «المغسلة هي اللي هتاخد
 * النسبة» — so the number an operator sets is now what the laundry receives,
 * and the platform keeps what is left of the basis.
 *
 * Stored rather than derived, for the reason every other figure here is: a
 * settled row is frozen, and a rate re-read from today's rule would restate
 * what a laundry was already paid.
 *
 * **Null is two honest things, never a guess.** On a row settled before this
 * release it means «divided the old way» — its lines are the platform's charges
 * and add up to `commission_amount`. On a pending row it means no share could
 * be found for the laundry, and `SettlementService::settleFor()` will not move
 * any money until somebody sets one. Paying the platform the whole basis
 * because nobody filled a box is the one outcome this column exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->decimal('laundry_share_rate', 5, 2)->nullable()->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->dropColumn('laundry_share_rate');
        });
    }
};
