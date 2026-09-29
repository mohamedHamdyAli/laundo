<?php

namespace App\Modules\Coupon\Services;

use App\Modules\Coupon\Models\Coupon;
use App\Modules\Coupon\Models\CouponRedemption;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\OrderPricing;
use App\Modules\Service\Repositories\ServiceRepository;
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
    public function __construct(
        private readonly OrderPricing $pricing,
        private readonly ServiceRepository $services,
    ) {}

    /**
     * «تطبيق» on the order summary: a code against what the app knows of the
     * basket. With the basket itself — a service and its pieces — it is priced
     * exactly as checkout prices it, so a code limited to some pieces is worked
     * out here as it will be there; with only a subtotal, as it always was.
     *
     * @param  array<int, array{item_id: int|string, qty: int|string}>|null  $items
     * @return array{coupon: Coupon, discount: float}
     *
     * @throws RuntimeException
     */
    public function check(string $code, User $customer, ?int $serviceId, ?array $items, float $subtotal, float $deliveryFee = 0): array
    {
        $lines = null;

        if ($items !== null && $items !== []) {
            $service = $this->services->findActive($serviceId);

            if (! $service) {
                throw new RuntimeException('service_not_found');
            }

            $priced = $this->pricing->lines($service, $items);
            $subtotal = $priced['subtotal'];
            $lines = $priced['lines'];
        }

        return $this->validate($code, $customer, $subtotal, $deliveryFee, $serviceId, $lines);
    }

    /**
     * Can this customer use this code on this basket, and for how much?
     *
     * A coupon limited to a service, some categories or some pieces needs the
     * basket itself — `$lines` as OrderPricing::lines() prices them — because
     * the discount comes off only what it applies to. Null means the caller
     * does not know the basket, which is fine for a coupon on the whole order
     * and refused, with a message saying why, for a limited one.
     *
     * @param  array<int, array{item_id: int, line_total: float|int|string}>|null  $lines
     * @return array{coupon: Coupon, discount: float}
     *
     * @throws RuntimeException
     */
    public function validate(
        string $code,
        User $customer,
        float $subtotal,
        float $deliveryFee = 0,
        ?int $serviceId = null,
        ?array $lines = null,
    ): array {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();

        if (! $coupon) {
            throw new RuntimeException('coupon_not_found');
        }

        if ($coupon->status !== 'active') {
            throw new RuntimeException('coupon_inactive');
        }

        // A coupon issued to one person — a referral reward, or goodwill after a
        // complaint. Reported as "not found" rather than "not yours", because
        // telling a stranger the code is real and belongs to somebody else is an
        // invitation to keep guessing.
        if ($coupon->user_id !== null && (int) $coupon->user_id !== (int) $customer->id) {
            throw new RuntimeException('coupon_not_found');
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

        // The part of the basket it applies to. The minimum above is still the
        // whole order's — «on orders over 200» is about the order.
        $eligible = $subtotal;

        if ($coupon->isScoped()) {
            // Not «nothing qualifies» when the caller simply did not say what is
            // in the basket — a service's code needs the service, a code on some
            // pieces needs the pieces.
            if ($coupon->scope_type === Coupon::SCOPE_SERVICE && $serviceId === null) {
                throw new RuntimeException('coupon_needs_service');
            }

            if ($coupon->scope_type !== Coupon::SCOPE_SERVICE && $lines === null) {
                throw new RuntimeException('coupon_needs_basket');
            }

            $eligible = $coupon->eligibleSubtotal($serviceId, $lines ?? [], $subtotal);

            if ($eligible <= 0) {
                throw new RuntimeException('coupon_not_for_basket');
            }
        }

        $discount = $coupon->discountFor($eligible, $coupon->coversDeliveryFee() ? $deliveryFee : 0);

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
            'coupon_not_for_basket' => __('This code is for other pieces or another service — nothing in this order qualifies.'),
            'coupon_needs_basket' => __('This code applies to some pieces only. Add them to your order and it is worked out at checkout.'),
            'coupon_needs_service' => __('This code is for particular services. Choose the service and it is worked out at checkout.'),
            'service_not_found' => __('Service not found.'),
            default => __('This code cannot be used.'),
        };
    }
}
