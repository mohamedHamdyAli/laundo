<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much work one driver may hold at once, and which city they work in.
 *
 * Both come from the dispatch rule: a driver has a maximum number of orders they
 * can carry, and their work stays inside one city rather than scattering across
 * areas.
 *
 * Recorded here in P6 because the columns belong with the driver's profile, but
 * enforced by the dispatcher in P8 — that is where an order exists to count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_concurrent_orders')->nullable()->after('is_available');
            $table->foreignId('city_id')->nullable()->after('max_concurrent_orders')
                ->constrained('cities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
            $table->dropColumn('max_concurrent_orders');
        });
    }
};
