<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a coupon applies to — «الأوفر على كاتيجوري معينة أو نوع خدمة معينة أو
 * item معين».
 *
 * An offer's discount *is* its coupon, so the limit lives here and a typed code
 * is limited the same way. One kind per coupon, several of it:
 *
 *   - null        the whole order, as every coupon has been until now;
 *   - `service`   orders of these services (the whole basket of one);
 *   - `category`  only the pieces in these item categories;
 *   - `item`      only these pieces.
 *
 * `scope_ids` is the list of ids of that kind. JSON rather than a pivot table:
 * it is read with the coupon on every quote, never queried by id, and a pivot
 * would be three tables for one list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('scope_type', 20)->nullable()->after('applies_to_delivery');
            $table->json('scope_ids')->nullable()->after('scope_type');
        });

        // The limit an order's coupon had when it was placed — `{type, ids}` —
        // copied like the rest of its terms and never re-read, so the review can
        // cap the discount at the pieces it applies to without a coupon re-scoped
        // later restating an order placed before.
        Schema::table('orders', function (Blueprint $table) {
            $table->json('discount_scope')->nullable()->after('discount_covers_delivery');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_scope');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'scope_ids']);
        });
    }
};
