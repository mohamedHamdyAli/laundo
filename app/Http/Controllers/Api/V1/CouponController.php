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
 *
 * **The basket, when the app has it.** A code limited to a service, some
 * categories or some pieces comes off only what it applies to, so a subtotal
 * alone cannot say how much it takes. Sent `service_id` and `items`, the check
 * prices the basket exactly as checkout does; sent only a subtotal, a limited
 * code is answered with a message saying it will be worked out on the order.
 * `subtotal` stays accepted so an app that has not changed keeps working.
 */
class CouponController extends Controller
{
    public function __construct(private readonly CouponService $coupons) {}

    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required_without:items', 'nullable', 'numeric', 'min:0'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'service_id' => ['required_with:items', 'nullable', 'integer'],
            'items' => ['nullable', 'array', 'min:1', 'max:200'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $result = $this->coupons->check(
                $request->get('code'),
                $request->user(),
                $request->filled('service_id') ? (int) $request->get('service_id') : null,
                $request->filled('items') ? (array) $request->get('items') : null,
                (float) $request->get('subtotal', 0),
                (float) $request->get('delivery_fee', 0),
            );
        } catch (RuntimeException $e) {
            $field = $e->getMessage() === 'service_not_found' ? 'service_id' : 'code';

            return failReturnValidation(
                [$field => [$this->coupons->message($e->getMessage())]],
                $this->coupons->message($e->getMessage())
            );
        }

        return successReturnData([
            'code' => $result['coupon']->code,
            'discount' => $result['discount'],
            'applies_to_delivery' => $result['coupon']->coversDeliveryFee(),
            // Null for a code on the whole order; otherwise what it is limited
            // to — {type: service|category|item, ids, names} — so the summary
            // can say «على القمصان والبناطيل».
            'applies_to' => $result['coupon']->scopeSummary(),
        ], __('Code applied.'));
    }
}
