<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the laundry priced the piece at, beside what the customer was charged.
 *
 * `unit_price` now carries the platform's fee folded in, because that is the
 * number the customer agreed to and the number the invoice has to show. But the
 * laundry's own figure has to survive somewhere, and re-deriving it by dividing
 * the fee back out does not work: the fee is rounded per piece, so the division
 * drifts — which is the whole reason `PlatformFee::within()` takes the fee by
 * subtraction rather than as a percentage.
 *
 * It is also a correctness fix, not only bookkeeping. «تنظيف جاف» is priced by
 * the laundry typing a figure, and the review form prefills that box from the
 * stored price. With only the inflated one stored, a customer asking for a
 * second count handed the laundry the fee-inclusive number, which was then
 * charged the fee again — 100 became 110, then 121, then 133.10, compounding
 * once per dispute while the laundry's own price never moved.
 *
 * Nullable, and null means «written before this column existed» — those rows
 * carry no fee, so `unit_price` is already the base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('base_unit_price', 10, 2)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('base_unit_price');
        });
    }
};
