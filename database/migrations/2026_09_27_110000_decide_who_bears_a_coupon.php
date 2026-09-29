<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who pays for a discount — the platform, the laundry, or both in proportion.
 *
 * Until now a coupon came off the washing before it was divided, so the laundry
 * bore its own percentage of every discount the platform handed out. The client
 * wants the platform to carry it by default, and the super admin to be able to
 * say otherwise per coupon: «يختار مين الهيشلها مغسلة ولا سوبر أدمن ولا نسبة ونسبة».
 *
 * One number says all three: **the share of the discount the laundry bears**,
 * 0–100. Zero is the platform, a hundred the laundry, anything between a split.
 *
 *   - `coupons.discount_laundry_share` — null means «follow the general setting»
 *     (`Coupon_Laundry_Share`), which is what every existing coupon and every
 *     referral reward minted automatically will do.
 *   - `orders.discount_laundry_share` and `orders.discount_covers_delivery` —
 *     **copied at placement and never re-read**, the same rule as the tax rate
 *     and the unit prices. A coupon edited next week must not restate who paid
 *     for an order placed today. Null on an order placed before this: the
 *     platform bears it, which is the owner's stated rule.
 *   - `order_settlements.discount_amount` and `laundry_discount_amount` — the
 *     discount on the order and the part of it the laundry actually bore, stored
 *     because a settled row is frozen and must not re-derive them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->decimal('discount_laundry_share', 5, 2)->nullable()->after('applies_to_delivery');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('discount_laundry_share', 5, 2)->nullable()->after('discount_total');
            $table->boolean('discount_covers_delivery')->default(false)->after('discount_laundry_share');
        });

        Schema::table('order_settlements', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('laundry_amount');
            $table->decimal('laundry_discount_amount', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_settlements', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'laundry_discount_amount']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount_laundry_share', 'discount_covers_delivery']);
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('discount_laundry_share');
        });
    }
};
