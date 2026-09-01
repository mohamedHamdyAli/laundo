<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «تمت إضافة أرباحك إلى الرصيد المعلق».
 *
 * One row per completed leg. `pending` while the order is still in flight, and
 * payable once it completes — the money has arrived by then, and paying a driver
 * for a delivery that was later returned would have to be clawed back.
 *
 * `rate` and `basis` are stored alongside the amount rather than only the result:
 * a driver asking why a job paid 12.50 needs to be shown the sum, and a rate that
 * changes next month must not silently restate last month's earnings. The same
 * rule as copying prices onto an order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_task_id')->constrained('order_tasks')->cascadeOnDelete();

            $table->decimal('amount', 10, 2);

            // The arithmetic, kept so it can be shown rather than recomputed.
            $table->decimal('basis', 10, 2);
            $table->decimal('rate', 5, 4);

            $table->string('status')->default('pending')->index();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // One earning per leg. A replayed completion must not pay twice.
            $table->unique('order_task_id');
            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_earnings');
    }
};
