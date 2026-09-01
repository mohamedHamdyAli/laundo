<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which zones a laundry serves — deferred from P1 because `zones` did not exist yet.
 *
 * This is the table the P6 assignment engine joins against: given a customer's
 * address zone and the service they picked, find a laundry that covers both.
 * Scoped by BelongsToLaundry, like laundry_services.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_id')->constrained('laundries')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['laundry_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_zones');
    }
};
