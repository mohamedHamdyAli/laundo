<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What P7 adds to an order.
 *
 * `review_terms_accepted_at` closes a gap P6 left: the order wizard shows a
 * required tick — «أوافق على مراجعة القطع وتحديد السعر النهائي قبل بدء التنظيف» —
 * and nothing recorded it. It is the customer's agreement to this entire
 * mechanism, so the moment it starts to matter is the moment it has to be on the
 * order rather than in a screenshot of a screen.
 *
 * `confirmed_at` is when the customer accepted the final price. Distinct from
 * `paid_at`, and that distinction is the whole shape of this phase: confirmation
 * releases the cleaning, money arrives on its own schedule.
 *
 * `review_round` counts how many times the pieces have been counted. A customer
 * who asks «طلب مراجعة إضافية» sends it round again, and an order on its third
 * count is a conversation someone should look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('review_terms_accepted_at')->nullable()->after('special_instructions');
            $table->timestamp('confirmed_at')->nullable()->after('reviewed_at');
            $table->unsignedTinyInteger('review_round')->default(0)->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['review_terms_accepted_at', 'confirmed_at', 'review_round']);
        });
    }
};
