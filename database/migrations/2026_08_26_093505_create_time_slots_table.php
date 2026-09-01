<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable pickup / delivery windows.
 *
 * Templates rather than a fixed grid, because the design shows windows of
 * different lengths — "02:00 مساءً – 05:00 مساءً" is three hours while
 * "5:00 م - 7:00 م" is two.
 *
 * Two deliberate omissions:
 *
 *   - No `days_of_week`. One set applies to every day, and every day is a working
 *     day. Adding per-weekday windows later is an additive migration.
 *   - `capacity` is nullable and null means unlimited. The column exists so a real
 *     operational cap can be set from the dashboard once throughput is known;
 *     enforcing it needs order counts, which arrive in P6.
 *
 * `applies_to` defaults to `both` — one set serves pickup and delivery today, and
 * the column is here so the two can be split without a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_slots', function (Blueprint $table) {
            $table->id();
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('applies_to', ['pickup', 'delivery', 'both'])->default('both');
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_slots');
    }
};
