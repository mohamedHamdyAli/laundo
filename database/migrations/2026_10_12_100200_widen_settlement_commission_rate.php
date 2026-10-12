<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `order_settlements.commission_rate` was `decimal(5,2)` — at most 999.99%.
 *
 * It is the platform's part as a percentage of the basis, for display. A
 * discount the platform funds almost to the whole washing leaves a basis of
 * piastres: walking the points programme on the development data produced a
 * basis of 0.55 against a platform part of −43.90, which is −7,981.82%, and
 * MySQL refused the insert — the order could not have been confirmed. Widened
 * so the figure stored is the true one rather than a clamped one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->decimal('commission_rate', 12, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->change();
        });
    }
};
