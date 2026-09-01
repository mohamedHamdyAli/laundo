<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pieces on an order.
 *
 * `unit_price` is **copied from the price matrix at the moment of ordering**, not
 * looked up later. A super admin changing a price tomorrow must not rewrite the
 * arithmetic of an order placed today — a retroactively edited invoice is a
 * dispute with a customer.
 *
 * `phase` separates what the customer counted from what the laundry counted, so
 * a review that finds an extra shirt («تم العثور على قطعة إضافية أثناء المراجعة»)
 * adds a `final` row without destroying the record of what was agreed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            $table->enum('phase', ['estimated', 'final'])->default('estimated');

            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);

            $table->timestamps();

            $table->index(['order_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
