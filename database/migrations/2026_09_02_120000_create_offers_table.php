<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «عروض متميزة» — the second carousel on the customer's home screen.
 *
 * It was being asked of the banners table, which cannot answer: the home screen
 * has two carousels with different card shapes, and `GET /api/v1/banners`
 * returns one flat list with no placement, so the app could only have guessed
 * («the first three are the hero») and operations could not have moved a card
 * from one to the other. Two endpoints, one for each carousel, and the question
 * never arises.
 *
 * The two things an offer has that a banner does not are why it is its own
 * table rather than a `placement` column: a validity window, so «استعد للشتاء»
 * stops showing in June without anybody remembering it, and a coupon, which is
 * where the «خصم 20%» badge comes from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            // Translatable JSON, as `text` — the convention in every other
            // table here. The model's accessors decode it.
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();

            /*
             * The badge's source. Nullable because not every offer is a
             * discount code — «باقة غسيل البطاطين» may just point at a service.
             *
             * `nullOnDelete` rather than cascade: deleting a coupon should cost
             * the offer its badge, not the offer itself. An operator who
             * removes a spent code has not asked for the card to vanish.
             */
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();

            // The same closed set of destinations banners use. `target_value`
            // holds either a service id or a coupon code, so — exactly as in
            // `banners` — it is deliberately not a foreign key.
            $table->string('target_type')->default('none');
            $table->string('target_value')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->integer('sort_order')->default(0);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            // The shape of the only query the API makes, and the same index
            // `coupons` already carries for the same reason.
            $table->index(['status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
