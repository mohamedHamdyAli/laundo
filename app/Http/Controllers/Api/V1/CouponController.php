<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Coupon\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * «تطبيق» on the order summary — checking a code before ordering.
 *
 * Checking never consumes. Most baskets a code is asked about are never ordered,
 * and spending a customer's single use of a welcome code on a screen they walked
 * away from would be indefensible.
 */
class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons) {}

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->coupons->validate(
                $request->get('code'),
                $request->user(),
                (float) $request->get('subtotal'),
                (float) $request->get('delivery_fee', 0),
            );
        } catch (RuntimeException $e) {
            return failReturnValidation(
                ['code' => [$this->coupons->message($e->getMessage())]],
                $this->coupons->message($e->getMessage())
            );
        }

        return successReturnData([
            'code' => $result['coupon']->code,
            'discount' => $result['discount'],
            'applies_to_delivery' => $result['coupon']->applies_to_delivery,
        ], __('Code applied.'));
    }
}
