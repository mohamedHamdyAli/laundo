<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own fee, on the row that divides the order.
 *
 * The table's own rule is that **every component is stored, not just the
 * answer** — so a settlement can be re-cut later without archaeology. A third
 * party now takes money out of an order, and leaving its share to be inferred
 * from the gap between the basis and the subtotal would be exactly the
 * archaeology that rule exists to prevent.
 *
 * Beside `tax_amount` rather than inside `commission_amount`: the two are paid
 * by different people for different reasons — this one by the customer, the
 * commission by the laundry — and a revenue screen that cannot tell them apart
 * cannot answer what the platform earned from whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->decimal('platform_fee_amount', 10, 2)->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->dropColumn('platform_fee_amount');
        });
    }
};
