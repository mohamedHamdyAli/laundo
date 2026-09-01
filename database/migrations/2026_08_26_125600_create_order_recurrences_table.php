<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's saved repeat schedule.
 *
 * Created before `orders` because an order may point back at the schedule that
 * produced it.
 *
 * The schedule does NOT generate orders on its own. On its due day the system
 * asks «محتاج تغسل النهاردة؟» and only creates an order if the customer says yes —
 * that is the business decision, and `recurrence_prompts` records each asking.
 *
 * The order's own details are stored here so a confirmation needs no further
 * input: the service, the items, the address and the pickup window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('pickup_address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('time_slot_id')->nullable()->constrained('time_slots')->nullOnDelete();

            // أسبوعي / كل أسبوعين / شهري
            $table->enum('frequency', ['weekly', 'biweekly', 'monthly']);

            // Which weekday the design's copy refers to ("كل يوم اثنين"). 1 = Monday.
            $table->unsignedTinyInteger('day_of_week')->nullable();

            // The saved basket: [{item_id, qty}]. A snapshot of intent, not of
            // price — pricing happens when the order is actually created.
            $table->json('items');

            $table->date('next_prompt_on')->nullable();
            $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['status', 'next_prompt_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_recurrences');
    }
};
