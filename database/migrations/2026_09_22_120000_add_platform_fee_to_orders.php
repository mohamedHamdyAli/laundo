<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own charge, recorded on the order that carried it.
 *
 * Stored rather than derived, for the same reason `tax_rate` is: the rate is
 * copied at placement and never read again, so raising it next month cannot
 * restate what somebody already agreed to and already paid.
 *
 * Both columns nullable, and null means «placed before this existed» — which is
 * a different fact from zero, and the settlement reads it that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The amount inside the subtotal, not a percentage of anything at
            // read time: the per-piece rounding already happened, so this is the
            // figure the invoice actually contains.
            $table->decimal('platform_fee', 10, 2)->nullable()->after('cash_surcharge');

            // Kept beside it so an operator reading an old order can see what it
            // was charged at, which is not necessarily what the settings say now.
            $table->decimal('platform_fee_rate', 5, 2)->nullable()->after('platform_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['platform_fee', 'platform_fee_rate']);
        });
    }
};
