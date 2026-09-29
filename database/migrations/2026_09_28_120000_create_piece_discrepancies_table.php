<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «عدد القطع المستلمة» compared with the handover before it.
 *
 * The driver's count was stored and compared with nothing: a customer ordering
 * one piece and a driver counting two raised no flag anywhere.
 *
 * Two parts. **On every counted leg**, what its count was measured against —
 * stamped at the handover, not worked out later, because the laundry's review
 * can change the order's count afterwards and the question is what the driver
 * was held to then. **One row per disagreement** in `piece_discrepancies`: at a
 * handover, or at the laundry's review when it counts a different number from
 * the one handed to it. A row stays open until somebody at the platform has
 * looked into it and said what they found and how many pieces there really are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_tasks', function (Blueprint $table) {
            $table->unsignedInteger('expected_piece_count')->nullable()->after('piece_count');
            // customer_order | previous_leg | laundry_review
            $table->string('expected_piece_source', 20)->nullable()->after('expected_piece_count');
        });

        Schema::create('piece_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // The leg whose count disagreed; null for the laundry's review.
            // Unique: a leg completes once, so it disagrees at most once — a
            // handover confirmed twice at once must not open two.
            $table->foreignId('order_task_id')->nullable()->unique()->constrained('order_tasks')->cascadeOnDelete();
            // PieceCheckStep: the three counted legs, or laundry_review.
            $table->string('step', 30);
            $table->unsignedInteger('counted');
            $table->unsignedInteger('expected');
            $table->string('expected_source', 20);
            // The driver, or whoever at the laundry entered the review.
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            // Indexed: the sidebar badge asks on every panel page.
            $table->boolean('open')->default(true)->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            // What the reviewer found — the number the next handover is held to.
            $table->unsignedInteger('confirmed_count')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'open']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piece_discrepancies');

        Schema::table('order_tasks', function (Blueprint $table) {
            $table->dropColumn(['expected_piece_count', 'expected_piece_source']);
        });
    }
};
