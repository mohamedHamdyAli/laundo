<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The state's tax, on the order that was actually agreed.
 *
 * `Tax` has been on the settings form, validated and stored since P9 and **read
 * by nothing** — the same fault `Cash_Surcharge` had, and found the same way.
 * An invoice with no tax line is an invoice that cannot be filed.
 *
 * Three columns rather than one, and the rate is the reason. Tax is charged at
 * the rate in force on the day, so the rate has to travel with the order exactly
 * as unit prices do: a state that raises 10% to 14% next quarter must not
 * restate every invoice already issued. `estimated_tax` is what the customer
 * agreed to, `final_tax` what the pieces came to once counted — the same pair
 * the rest of the order's money already keeps.
 *
 * Both amounts are stored rather than derived from the rate, because the rate is
 * a percentage and the amount is rounded: recomputing it is how a total stops
 * adding up by a piastre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nullable, not defaulted to zero: null means "placed before there
            // was a tax", which is a different fact from "taxed at nothing".
            $table->decimal('tax_rate', 5, 2)->nullable()->after('cash_surcharge');
            $table->decimal('estimated_tax', 10, 2)->default(0)->after('tax_rate');
            $table->decimal('final_tax', 10, 2)->nullable()->after('final_subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'estimated_tax', 'final_tax']);
        });
    }
};
