<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which services each laundry actually provides.
 *
 * This is the whole of a laundry's control over the catalogue: it chooses what
 * it offers, never what it costs. Scoped by BelongsToLaundry, so a laundry only
 * ever reads and writes its own rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_id')->constrained('laundries')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['laundry_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_services');
    }
};
