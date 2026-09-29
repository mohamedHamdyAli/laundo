<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue-wide price rise an order was placed under.
 *
 * A rise applied «for a period» lives in the settings and is read wherever a
 * piece price is — but the laundry's review reads the price matrix again, days
 * after the customer agreed. Without the rate stamped here, a period that ended
 * while the bag sat in the laundry would drop the final bill below the estimate
 * the customer accepted, and one that started would raise it.
 *
 * Same copy-at-placement rule as `platform_fee_rate` and `tax_rate`. Null is an
 * order placed before this existed, or with no rise in force: it prices at the
 * matrix exactly as it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('price_increase_rate', 5, 2)->nullable()->after('platform_fee_rate');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('price_increase_rate');
        });
    }
};
