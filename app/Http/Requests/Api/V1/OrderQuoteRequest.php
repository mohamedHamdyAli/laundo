<?php

namespace App\Http\Requests\Api\V1;

use App\Modules\Payment\Enums\PaymentMethod;
use App\Http\Requests\Api\V1\Concerns\OneDiscountPerOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The summary step's price preview.
 *
 * Only what pricing actually depends on: the service, the basket and the two
 * addresses. Scheduling and notes have no effect on the total, so demanding them
 * before showing a price would make the wizard ask for things in the wrong order.
 */
class OrderQuoteRequest extends FormRequest
{
        use OneDiscountPerOrder;

public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_id' => [
                'required',
                Rule::exists('services', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'items' => ['present', 'array'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'delivery_address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            // «تطبيق» sits on this very screen. Without the field here the code
            // was validated away before the service could look at it.
            'coupon_code' => ['nullable', 'string', 'max:50'],
            // «عروض متميزة» — the card the customer tapped to get here. Its
            // discount and a typed promo code are mutually exclusive; see
            // OneDiscountPerOrder.
            'offer_id' => [
                'nullable',
                'integer',
                Rule::exists('offers', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            // The same trap, and the same screen: «شاشة اختيار الدفع» re-quotes as
            // the customer picks a method, and the cash surcharge depends on which
            // one. Absent from these rules, the method was stripped before pricing
            // saw it and the fee could never appear.
            'payment_method' => ['nullable', 'string', 'in:'.implode(',', PaymentMethod::values())],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $this->refuseASecondDiscount($validator);
    }
}
