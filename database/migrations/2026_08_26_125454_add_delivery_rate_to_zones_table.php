<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-kilometre delivery rate, set per zone.
 *
 * The zone is the customer's pickup zone, so a far-flung area can carry a higher
 * rate than a central one while the arithmetic stays the same everywhere:
 * distance x rate.
 *
 * `min_delivery_fee` exists because distance alone produces absurd results at
 * short range — a 300-metre trip should not cost 1.50. It floors the result.
 *
 * Null rate means "not priced yet", which the calculator surfaces instead of
 * silently charging nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->decimal('price_per_km', 8, 2)->nullable()->after('name');
            $table->decimal('min_delivery_fee', 8, 2)->nullable()->after('price_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn(['price_per_km', 'min_delivery_fee']);
        });
    }
};
