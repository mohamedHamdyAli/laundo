<?php

namespace App\Modules\Coupon\Services;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «ادعُ أصدقاءك — خصومات حصرية لك ولهم».
 *
 * The owner's decision was both sides, **after the friend's first paid order**.
 * The timing is the design: a reward at sign-up is free to manufacture, and any
 * referral programme that pays on registration is a programme somebody farms with
 * a handful of phone numbers. Waiting for money to arrive means the only way to
 * mint a coupon is to become a customer.
 *
 * The reward itself is **off until somebody sets it**. Its size is the owner's
 * decision and not a default worth inventing, so an unconfigured install records
 * the referral and issues nothing rather than guessing a discount.
 */
class ReferralService
{
    /** How long an issued reward stays usable. */
    private const VALID_FOR_DAYS = 90;

    /**
     * A code for a new customer.
     *
     * Prefixed so a customer reading it aloud on the phone knows it is ours, and
     * upper-cased because it is typed by somebody who was told it in a message.
     */
    public static function mintCode(): string
    {
        do {
            $code = 'LAUNDO-'.Str::upper(Str::random(6));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * Attach a new customer to whoever invited them.
     *
     * Returns the inviter, or null when the code was blank, unknown, or their
     * own — self-referral being the first thing anybody tries.
     */
    public function link(User $newCustomer, ?string $code): ?User
    {
        if (blank($code)) {
            return null;
        }

        $inviter = User::where('referral_code', trim($code))->first();

        if (! $inviter || $inviter->id === $newCustomer->id) {
            return null;
        }

        $newCustomer->forceFill(['referred_by' => $inviter->id])->save();

        return $inviter;
    }

    /**
     * Pay both sides, once, when the invited customer's first order is paid for.
     *
     * Called from an `updated` hook on the order rather than from the two places
     * that settle payment — cash on the doorstep and a captured card — because a
     * third way to settle will arrive and this must not be the thing that is
     * forgotten.
     */
    public function rewardFor(Order $order): void
    {
        $customer = $order->customer;

        if (! $customer || $customer->referred_by === null || $customer->referral_rewarded_at !== null) {
            return;
        }

        $inviter = User::find($customer->referred_by);

        if (! $inviter) {
            return;
        }

        [$type, $value] = $this->configuredReward();

        DB::transaction(function () use ($customer, $inviter, $type, $value) {
            // Stamped whether or not a coupon is issued. An install that had no
            // reward configured on the day somebody's friend ordered must not
            // start paying out retroactively the moment the setting is filled in.
            $customer->forceFill(['referral_rewarded_at' => now()])->save();

            if ($value <= 0) {
                return;
            }

            foreach ([$customer, $inviter] as $person) {
                $this->issue($person, $type, $value);
            }
        });
    }

    /**
     * A coupon only one named person can use.
     *
     * `user_id` is what makes it a reward rather than a code: without it the
     * first person who hears about it spends it, and the person who earned it
     * finds out at checkout.
     */
    public function issue(User $person, string $type, float $value): Coupon
    {
        do {
            $code = 'REF-'.Str::upper(Str::random(8));
        } while (Coupon::where('code', $code)->exists());

        return Coupon::create([
            'code' => $code,
            'user_id' => $person->id,
            'name' => json_encode([
                'en' => 'Referral reward',
                'ar' => 'مكافأة الدعوة',
            ], JSON_UNESCAPED_UNICODE),
            'type' => $type,
            'value' => $value,
            'max_redemptions' => 1,
            'max_per_user' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addDays(self::VALID_FOR_DAYS),
            'status' => 'active',
        ]);
    }

    /**
     * What the owner set, or nothing.
     *
     * @return array{0: string, 1: float}
     */
    private function configuredReward(): array
    {
        $type = getSettingValue('Referral_Reward_Type') === Coupon::FIXED
            ? Coupon::FIXED
            : Coupon::PERCENTAGE;

        $value = (float) (getSettingValue('Referral_Reward_Value') ?? 0);

        // Never negative, whatever is in the settings table. A reward that pays
        // the platform is not a reward.
        return [$type, max(0.0, $value)];
    }
}
