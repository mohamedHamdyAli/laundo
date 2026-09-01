<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * «ادعُ أصدقاءك — شارك رمز الدعوة واحصل على خصومات حصرية لك ولهم».
 *
 * The owner's decision: both sides get a coupon, and it lands **after the friend's
 * first paid order** rather than at sign-up. That timing is the whole design. A
 * reward at registration is free to manufacture — anyone with five phone numbers
 * mints five coupons and nobody washes anything — while a reward that waits for
 * money to arrive cannot be faked without actually becoming a customer.
 *
 * `coupons.user_id` is the other half of it. Coupons here have always been public
 * codes limited by `max_redemptions`; a personal reward issued as a public code is
 * a reward for whoever hears about it first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The code this person shares.
            $table->string('referral_code', 24)->nullable()->unique()->after('customer_reference');
            // Who brought them. Nulled rather than cascaded on delete: a customer
            // closing their account must not delete the person they invited.
            $table->foreignId('referred_by')->nullable()->after('referral_code')
                ->constrained('users')->nullOnDelete();
            // Set once, when the reward is actually issued, so the award can never
            // run twice however many times an order is marked paid.
            $table->timestamp('referral_rewarded_at')->nullable()->after('referred_by');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('code')
                ->constrained('users')->cascadeOnDelete();
        });

        $roleId = DB::table('roles')->where('slug', Role::USER)->value('id');

        if ($roleId === null) {
            return;
        }

        // Existing customers get a code too — the screen is in their account and
        // it would otherwise be blank for everybody who signed up before today.
        DB::table('users')->where('role_id', $roleId)->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('users')->where('id', $row->id)->update([
                        'referral_code' => 'LAUNDO-'.Str::upper(Str::random(6)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referral_rewarded_at']);
        });
    }
};
