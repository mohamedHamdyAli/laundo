<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «خصم الترحيب» and whatever follows it.
 *
 * The design already does the arithmetic — التنظيف 270 + التوصيل 20 − الخصم 10 =
 * 280 — so `orders.discount_total` has had a named source waiting for it since P6
 * and has never once been non-zero.
 *
 * Two caps, because they answer different questions: `max_redemptions` is "how
 * much is this campaign allowed to cost", and `max_per_user` is "can one person
 * take it repeatedly". A coupon with only the first is a coupon one customer can
 * drain.
 *
 * `applies_to_delivery` exists because a free-delivery offer and a discount on the
 * cleaning are different products, and collapsing them would make «توصيل مجاني»
 * unrepresentable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->json('name')->nullable();

            $table->string('type');
            $table->decimal('value', 10, 2);

            // A percentage without a ceiling is an open cheque on a large order.
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->decimal('min_order_total', 10, 2)->nullable();

            $table->boolean('applies_to_delivery')->default(false);

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_per_user')->default(1);
            $table->unsignedInteger('redemptions_count')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->decimal('amount', 10, 2);
            $table->timestamps();

            // One redemption per order, enforced rather than trusted: a retried
            // request must not spend the same coupon twice on one basket.
            $table->unique(['coupon_id', 'order_id']);
            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
