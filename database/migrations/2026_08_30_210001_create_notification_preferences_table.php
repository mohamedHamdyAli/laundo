<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The design's «الإشعارات» toggle, made real.
 *
 * A row per (user, channel) that is **absent by default**: no row means the
 * channel is on. Storing only the exceptions means a new channel is opted-in for
 * everybody without a backfill, and a user who never touched the toggle has no
 * row at all — which is also the honest representation of "never expressed a
 * preference".
 *
 * Note what this table cannot do: silence a transactional message. A customer who
 * muted notifications still has to be told the order is waiting on them, or the
 * order simply stalls and they never learn why. That rule lives in the
 * dispatcher, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('channel');
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
