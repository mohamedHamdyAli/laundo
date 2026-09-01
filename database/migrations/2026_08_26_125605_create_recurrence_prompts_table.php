<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per asking of «محتاج تغسل النهاردة؟».
 *
 * The schedule alone cannot answer "did we ask, and what did they say" — and
 * without that the scheduler would either ask twice or silently skip. A prompt
 * per cycle, unique on (recurrence, date), makes the run idempotent however many
 * times the command fires.
 *
 * `answer` starts null. Confirming creates the order and links it here; declining
 * or never answering leaves the cycle skipped and the schedule alive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrence_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurrence_id')->constrained('order_recurrences')->cascadeOnDelete();

            $table->date('prompted_for');
            $table->timestamp('prompted_at')->nullable();

            $table->enum('answer', ['confirmed', 'declined'])->nullable();
            $table->timestamp('answered_at')->nullable();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->timestamps();

            // The idempotency guarantee: one prompt per cycle, no matter how often
            // the scheduler runs.
            $table->unique(['recurrence_id', 'prompted_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_prompts');
    }
};
