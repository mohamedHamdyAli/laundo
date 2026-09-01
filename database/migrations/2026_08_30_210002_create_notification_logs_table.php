<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was sent, to whom, over what, and whether it arrived.
 *
 * Without this a "I never got it" is unanswerable and an SMS bill is unauditable
 * — the two questions anybody actually asks about a notification system.
 *
 * `failure_reason` matters more than it looks: a push token that FCM has
 * invalidated fails on every send forever, and the only way to notice is to be
 * able to count the failures per token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('event');
            $table->string('channel');
            $table->string('status');

            $table->string('destination')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->text('failure_reason')->nullable();

            $table->nullableMorphs('subject');

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'status']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
