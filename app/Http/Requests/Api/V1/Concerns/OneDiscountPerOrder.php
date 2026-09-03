<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Modules\Offer\Models\Offer;
use Illuminate\Validation\Validator;

/**
 * One discount per order.
 *
 * A customer may type a promo code while placing the order, or arrive through an
 * offer from the home carousel — never both. The offer is what they chose first
 * and what the card promised them, so it wins, and a code sent alongside it is
 * refused rather than dropped: silently charging the offer's price while the
 * customer believes a second discount applied is the worst of the three
 * outcomes.
 *
 * Refused rather than merely priced-without, too, because by the time the
 * customer presses submit the quote has already told them — the wizard prices
 * every change through the same rule.
 *
 * The restriction is on two *discounts*, not on offers: an offer pointing at a
 * service («تنظيف جاف» say) carries no coupon, so a promo code beside it is
 * fine and this passes it through.
 */
trait OneDiscountPerOrder
{
    protected function refuseASecondDiscount(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $offerId = $this->input('offer_id');
            $code = $this->input('coupon_code');

            if (! $offerId || blank($code)) {
                return;
            }

            // `live()` and not `find()`: an expired offer is not a discount, so
            // it cannot be the reason a code is refused. An unusable offer_id
            // fails its own `exists` rule separately.
            $offer = Offer::live()->with('coupon')->find($offerId);

            if (! $offer || ! $offer->badge()) {
                return;
            }

            $validator->errors()->add(
                'coupon_code',
                __('This offer already includes its discount — a promo code cannot be added to it.')
            );
        });
    }
}
