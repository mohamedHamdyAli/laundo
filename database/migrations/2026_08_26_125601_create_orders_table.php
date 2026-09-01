<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order.
 *
 * Two things worth reading closely.
 *
 * **Two price sets, not one.** `estimated_*` is what the customer agreed to when
 * ordering, from their own count of the pieces. `final_*` is what the laundry
 * arrives at after physically counting them in P7. Keeping both means the
 * difference is always auditable — which matters, because the customer has to
 * approve the final figure before anything is cleaned.
 *
 * **`laundry_id` is nullable.** By decision an order is accepted even when no
 * laundry covers the zone or offers the service; it lands unassigned and
 * operations assign it. Rejecting at the door would lose the order.
 *
 * `delivery_fee` is stored, not derived. It is distance x the pickup zone's
 * per-km rate at the time of ordering, and neither the rate nor the laundry's
 * location may retroactively change a placed order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // The human reference, as «طلب رقم #10244» in the design.
            $table->string('code')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('laundry_id')->nullable()->constrained('laundries')->nullOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();

            $table->string('status')->index();

            $table->foreignId('pickup_address_id')->constrained('addresses')->restrictOnDelete();
            $table->foreignId('delivery_address_id')->constrained('addresses')->restrictOnDelete();

            $table->foreignId('pickup_slot_id')->nullable()->constrained('time_slots')->nullOnDelete();
            $table->foreignId('delivery_slot_id')->nullable()->constrained('time_slots')->nullOnDelete();
            $table->date('pickup_date')->nullable();
            $table->date('delivery_date')->nullable();

            // «الاستلام من خارج الباب» / «اتركها عند الباب»
            $table->enum('delivery_method', ['door', 'leave'])->default('door');

            $table->text('driver_note')->nullable();
            $table->text('special_instructions')->nullable();

            // What the customer counted, and what it came to.
            $table->unsignedInteger('estimated_items_count')->default(0);
            $table->decimal('estimated_subtotal', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('estimated_total', 10, 2)->default(0);

            // Filled by the laundry's review in P7.
            $table->unsignedInteger('final_items_count')->nullable();
            $table->decimal('final_subtotal', 10, 2)->nullable();
            $table->decimal('final_total', 10, 2)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('coupon_code')->nullable();

            // Payment lands in P9; the columns exist so the state machine can
            // already speak about it.
            $table->string('payment_method')->nullable();
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');
            $table->timestamp('paid_at')->nullable();

            // Scanned by the driver at all four handover points (P8).
            $table->string('qr_token', 64)->unique();

            $table->foreignId('recurrence_id')->nullable()
                ->constrained('order_recurrences')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['laundry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
