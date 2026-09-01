<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the cash surcharge on the order.
 *
 * `Cash_Surcharge` has been on the settings form, validated and stored since P9,
 * and nothing read it — a configured surcharge changed no price at all.
 *
 * Now that it does, the order has to hold the amount rather than only its effect
 * on the total. Without it, `estimated_total` is a figure nobody can reconstruct:
 * subtotal plus delivery minus discount would not add up, and an invoice printed
 * a month after somebody changed the setting would disagree with the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Default zero, not nullable: every order has a surcharge, and for
            // almost all of them it is nothing. A null would mean "unknown", which
            // is not a state this can be in.
            $table->decimal('cash_surcharge', 10, 2)->default(0)->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cash_surcharge');
        });
    }
};
