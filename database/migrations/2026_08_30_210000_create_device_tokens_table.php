<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a push notification is actually delivered.
 *
 * One row per device, not per user: a customer with a phone and a tablet expects
 * both to buzz, and a driver who changed handsets must not go silent because the
 * old token is still the one on file.
 *
 * `token` is unique across the table rather than per user, because a handset that
 * changes hands genuinely does move: FCM reissues the same token to whoever
 * installs the app next, and two users both believing they own it is how somebody
 * receives another person's order updates.
 *
 * `last_used_at` exists so a token nobody has seen in months can be pruned. FCM
 * rejects stale tokens, and a table that only ever grows makes every send slower
 * and every failure log noisier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('token', 512)->unique();
            $table->string('platform')->nullable();
            $table->string('app')->nullable();
            $table->string('locale', 8)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
