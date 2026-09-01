<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «لدي استفسار عن السعر».
 *
 * The design puts this button beside the confirm button, which makes it a real
 * obligation: a customer who taps it expects someone to answer. Support threads
 * proper are P10, so what this table promises is deliberately smaller and
 * honest — the question is recorded against the order, it appears in the
 * dashboard, and someone can mark it answered.
 *
 * Kept apart from `order_status_logs` because a question is not a movement. The
 * order does not change state when it is asked; that is precisely why it is easy
 * to lose, and why it needs a row of its own rather than a note in a log nobody
 * filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_price_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('message');

            // Nullable until somebody replies. The pair (answered_at, answered_by)
            // is what turns an open question into a closed one.
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The dashboard's open-questions queue reads exactly this.
            $table->index(['order_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_price_queries');
    }
};
