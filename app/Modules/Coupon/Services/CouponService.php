<?php

namespace App\Modules\Coupon\Services;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validating and spending a discount code.
 *
 * Validation and redemption are separate on purpose. The wizard checks a code
 * while the customer is still choosing — «تطبيق» on the summary screen — and that
 * check must not consume anything, because most of the baskets it is asked about
 * are never ordered.
 *
 * Redemption happens once, when the order is placed, and is guarded by a unique
 * key rather than a preceding count: two requests racing would both pass a check
 * and both spend the last redemption of a campaign.
 */
class CouponService
{
    /**
     * Can this customer use this code on this basket, and for how much?
     *
     * @return array{coupon: Coupon, discount: float}
     *
     * @throws RuntimeException
     */
    public function validate(string $code, User $customer, float $subtotal, float $deliveryFee = 0): array
    {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();

        if (! $coupon) {
            throw new RuntimeException('coupon_not_found');
        }

        if ($coupon->status !== 'active') {
            throw new RuntimeException('coupon_inactive');
        }

        if (! $coupon->hasStarted()) {
            throw new RuntimeException('coupon_not_started');
        }

        if ($coupon->hasExpired()) {
            throw new RuntimeException('coupon_expired');
        }

        if ($coupon->isExhausted()) {
            throw new RuntimeException('coupon_exhausted');
        }

        if ($coupon->min_order_total !== null && $subtotal + 0.001 < (float) $coupon->min_order_total) {
            throw new RuntimeException('coupon_minimum_not_met');
        }

        $used = CouponRedemption::where('coupon_id', $coupon->id)
            ->where('user_id', $customer->id)
            ->count();

        if ($used >= $coupon->max_per_user) {
            throw new RuntimeException('coupon_already_used');
        }

        $discount = $coupon->discountFor($subtotal, $deliveryFee);

        if ($discount <= 0) {
            // A code that takes nothing off is worse than no code: the customer
            // believes they have a discount.
            throw new RuntimeException('coupon_has_no_effect');
        }

        return ['coupon' => $coupon, 'discount' => $discount];
    }

    /**
     * Spend it.
     *
     * Idempotent per order: the unique key on (coupon, order) means a retry
     * returns the existing redemption rather than double-counting a campaign.
     */
    public function redeem(Coupon $coupon, User $customer, Order $order, float $amount): CouponRedemption
    {
        return DB::transaction(function () use ($coupon, $customer, $order, $amount) {
            $existing = CouponRedemption::where('coupon_id', $coupon->id)
                ->where('order_id', $order->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            $redemption = CouponRedemption::create([
                'coupon_id' => $coupon->id,
                'user_id' => $customer->id,
                'order_id' => $order->id,
                'amount' => round($amount, 2),
            ]);

            // Incremented atomically rather than read-then-write, so two orders
            // placed at once cannot both see the same count.
            Coupon::where('id', $coupon->id)->increment('redemptions_count');

            return $redemption;
        });
    }

    /**
     * Give a redemption back — an order cancelled before it was ever fulfilled
     * should not have spent the customer's one use of a welcome code.
     */
    public function release(Order $order): void
    {
        $redemptions = CouponRedemption::where('order_id', $order->id)->get();

        foreach ($redemptions as $redemption) {
            DB::transaction(function () use ($redemption) {
                Coupon::where('id', $redemption->coupon_id)
                    ->where('redemptions_count', '>', 0)
                    ->decrement('redemptions_count');

                $redemption->delete();
            });
        }
    }

    /**
     * The customer-facing reason a code was refused.
     */
    public function message(string $code): string
    {
        return match ($code) {
            'coupon_not_found' => __('This code is not valid.'),
            'coupon_inactive' => __('This code is no longer active.'),
            'coupon_not_started' => __('This code is not available yet.'),
            'coupon_expired' => __('This code has expired.'),
            'coupon_exhausted' => __('This code has been fully claimed.'),
            'coupon_minimum_not_met' => __('Your order is below the minimum for this code.'),
            'coupon_already_used' => __('You have already used this code.'),
            'coupon_has_no_effect' => __('This code does not apply to your order.'),
            default => __('This code cannot be used.'),
        };
    }
}
