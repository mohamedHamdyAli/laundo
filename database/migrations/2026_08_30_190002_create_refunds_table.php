<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «طلب استرداد».
 *
 * The design draws a **«قيد المراجعة»** state on a refund, which settles the
 * question of whether one is automatic: it is requested, a person decides, and
 * only then does money move. Approving and paying are separate columns for the
 * same reason — an approval that failed to pay out is the case somebody has to
 * chase, and a single status would hide it.
 *
 * `destination` records where the money went: back to the card through the
 * provider, or into the customer's wallet. A wallet credit is instant and a
 * gateway refund is not, so the customer is told different things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->decimal('amount', 10, 2);
            $table->string('reason');
            $table->text('note')->nullable();

            $table->string('status')->default('pending')->index();
            $table->string('destination')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
