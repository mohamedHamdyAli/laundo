<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits «verify the code» from «set the new password».
 *
 * The reset used to take the phone, the code and the new password in one call,
 * which forced the app to carry the code across two screens and re-send it —
 * the OTP screen verified nothing, it just held the digits until the password
 * screen submitted them. Now the code is spent at the verify step and this
 * token is what the password step presents instead.
 *
 * Stored as a SHA-256 digest rather than a bcrypt hash, so it can be found in
 * one indexed lookup. That is safe here and would not be for the OTP beside it:
 * a six-digit code has a million possibilities and a salt-free digest of one is
 * worth precomputing, while this token is 32 random bytes.
 *
 * Columns on `users` rather than a table of their own, matching the OTP that
 * precedes it: a customer has at most one reset in flight, and it is single-use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password_reset_token', 64)->nullable()->after('otp_attempts');
            $table->timestamp('password_reset_token_expires_at')->nullable()->after('password_reset_token');

            // The password step looks the account up by token alone, so this is
            // the lookup's index, not an afterthought.
            $table->index('password_reset_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['password_reset_token']);
            $table->dropColumn(['password_reset_token', 'password_reset_token_expires_at']);
        });
    }
};
