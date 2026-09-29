<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed what, everywhere.
 *
 * «محتاجين نعمل logs نعرف مين اللي عمل أي تغيير في أي أكشن». Until now only an
 * order's status recorded its actor (`order_status_logs`); a price, a coupon, a
 * laundry, a setting — and, on an order, the pieces, the laundry it was given,
 * a refund — changed with no record of who or what it had been.
 *
 * One row per model created, updated or deleted, written by
 * App\Services\ActivityLogger from the Eloquent events, so a screen added
 * next month is recorded without anybody remembering to.
 *
 *   - The actor's name and role are **copied**, not only referenced: an account
 *     deleted later must not turn «who did this» into a blank.
 *   - `subject_label` is copied for the same reason — the coupon's code, the
 *     laundry's name — so a deleted record is still recognisable.
 *   - `order_id` is set whenever the record belongs to an order, which is what
 *     the history on the order's screen reads.
 *   - `diff` holds each field before and after; secrets are recorded as
 *     changed without their value (config/activity.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_role', 64)->nullable();

            // dashboard | api | system
            $table->string('source', 16)->index();
            // created | updated | deleted | login | logout
            $table->string('event', 16)->index();

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();

            $table->unsignedBigInteger('order_id')->nullable()->index();

            $table->json('diff')->nullable();

            $table->string('route')->nullable();
            $table->string('ip', 45)->nullable();

            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
