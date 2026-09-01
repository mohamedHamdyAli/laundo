<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The zones a driver covers — «المناطق التي أخدمها» — deferred here from P1 and
 * P3 because `zones` did not exist yet.
 *
 * The other half of the P8 dispatch join: given an order's pickup zone, find a
 * driver who serves it, is available, and is inside their shift.
 *
 * `driver_id` points at `users`, not a drivers table: drivers are users with the
 * `driver` role, the same way laundry staff are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['driver_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_zones');
    }
};
