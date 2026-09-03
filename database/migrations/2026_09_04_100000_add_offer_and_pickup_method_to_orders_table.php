<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two decisions from the order-wizard screens.
 *
 * **`offer_id`** — one discount per order. A customer may type a promo code
 * while placing the order, or arrive through an offer from the home carousel,
 * never both. Without recording which offer they came through, the server
 * cannot tell a code the customer typed from a code an offer supplied, and so
 * cannot refuse the second one.
 *
 * It also answers the question the offer targets were made a closed set for:
 * which offer produced which order. A free URL would have made that
 * unanswerable, and so does a discount with no provenance.
 *
 * `nullOnDelete`, not cascade: retiring an offer must not delete the orders it
 * won, and an order that loses its provenance is still an order.
 *
 * **`pickup_method`** — the design asks how to hand over twice, once for the
 * collection and once for the return, and there was one column for both. A
 * customer who wants to put the bag into someone's hands but have the clean
 * clothes left at the door could not say so. `delivery_method` keeps its name
 * and its meaning — the return leg — and this is the collection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->after('coupon_code')
                ->constrained('offers')->nullOnDelete();

            $table->enum('pickup_method', ['door', 'leave'])
                ->default('door')->after('delivery_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['offer_id']);
            $table->dropColumn(['offer_id', 'pickup_method']);
        });
    }
};
