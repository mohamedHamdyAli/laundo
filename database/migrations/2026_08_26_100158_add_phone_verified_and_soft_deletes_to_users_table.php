<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phone verification and soft deletes on users.
 *
 * `phone_verified_at` matters because phone is the primary identity in this
 * product — email is optional at registration — yet the table only tracked
 * `email_verified_at`.
 *
 * `deleted_at` implements the soft-delete decision for account closure: orders
 * and invoices stay attached for accounting and disputes. A side effect worth
 * knowing: the unique indexes on `phone` and `email` still cover soft-deleted
 * rows, so a closed account's phone cannot be registered again. That matches the
 * decision taken, rather than working against it.
 *
 * Existing dashboard users are backfilled as verified — they were created by an
 * admin, not through the OTP flow, and would otherwise be locked out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
            $table->softDeletes();
        });

        // Accounts that predate this flow were created administratively, never
        // through the OTP path, so they would otherwise be unable to sign in.
        DB::table('users')
            ->whereNull('phone_verified_at')
            ->update(['phone_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_verified_at', 'otp_attempts']);
            $table->dropSoftDeletes();
        });
    }
};
