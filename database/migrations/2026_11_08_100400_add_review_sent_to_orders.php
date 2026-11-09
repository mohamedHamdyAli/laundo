<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «فحص القطع من المغسلة بس، وبعدها الأدمن يبعت للعميل» — the client's sheet,
 * item 12 (2026-11-08).
 *
 * The laundry's count and prices used to reach the customer the moment it was
 * saved. Now it is a draft the platform reviews and sends: `review_sent_round`
 * is the `review_round` that was sent (+ when and by whom). A review saved
 * after it bumps `review_round` past it — a new draft. Rounds, not timestamps:
 * a recount saved in the same second as the last sending must still read as
 * unsent.
 *
 * Every order already reviewed was, in effect, sent when it was reviewed — it
 * is stamped so, or the customer would lose a price they are already looking at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('review_sent_round')->nullable()->after('reviewed_at');
            $table->timestamp('review_sent_at')->nullable()->after('review_sent_round');
            $table->foreignId('review_sent_by')->nullable()->after('review_sent_at')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('orders')
            ->whereNotNull('reviewed_at')
            ->whereNotNull('final_total')
            ->update([
                'review_sent_round' => DB::raw('review_round'),
                'review_sent_at' => DB::raw('reviewed_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_sent_by');
            $table->dropColumn(['review_sent_round', 'review_sent_at']);
        });
    }
};
