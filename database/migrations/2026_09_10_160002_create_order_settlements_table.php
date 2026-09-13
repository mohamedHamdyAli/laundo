<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one order's money was divided between the platform and the laundry.
 *
 * The same shape as `driver_earnings`, for the same reasons: a share of somebody
 * else's money, recorded as a row before it moves and settled when the order is
 * certainly done. `wallet_transactions` says a balance changed; this says *why*
 * that number and not another one, which is the question a laundry disputing its
 * payout actually asks.
 *
 * **Every component is stored, not just the answer.** basis, rate, commission
 * and the laundry's share are all on the row, so the arithmetic can be checked
 * without knowing what the settings said that month — the same rule that puts
 * `rate` on a driver earning and unit prices on an order item.
 *
 * `tax_amount` rides along and is never divided. It is the state's, so it is
 * neither the platform's revenue nor the laundry's, and recording it here is
 * what lets the settlement reconcile back to the invoice total.
 *
 * One row per order, enforced by the unique key: a replayed completion must not
 * pay a laundry twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();

            // Nullable for the same reason `orders.laundry_id` is: an order can
            // exist before anybody is assigned to clean it. A settlement without
            // a laundry cannot be paid out, and shows on the screen as exactly
            // that rather than vanishing.
            $table->foreignId('laundry_id')->nullable()->constrained('laundries')->nullOnDelete();

            // The order total excluding tax — what the two parties are dividing.
            $table->decimal('basis', 10, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('laundry_amount', 10, 2);

            // Carried for reconciliation, never split.
            $table->decimal('tax_amount', 10, 2)->default(0);

            // pending  — recorded, nothing moved
            // settled  — both wallets credited
            // cancelled— the order never completed, so nothing is owed
            $table->string('status')->default('pending')->index();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(['laundry_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_settlements');
    }
};
