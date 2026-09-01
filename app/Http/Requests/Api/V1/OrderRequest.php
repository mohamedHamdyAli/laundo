<?php

namespace App\Http\Requests\Api\V1;

use App\Modules\Payment\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The order wizard's payload.
 *
 * Addresses are validated only as "an address that exists" — that they belong to
 * *this* customer is settled in OrderService, by looking them up through the
 * user's own relation. A rule here would duplicate that check and could drift
 * from it.
 */
class OrderRequest extends FormRequest
{
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

            // A quote-priced service («تنظيف جاف» costed after inspection) may
            // legitimately arrive with no basket, so `present` rather than
            // `required` — OrderService rejects an empty basket for the per-item
            // services where it matters.
            'items' => ['present', 'array'],
            'items.*.item_id' => [
                'required',
                Rule::exists('items', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],

            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            // Absent means "same address", which is the design's default.
            'delivery_address_id' => ['nullable', 'integer', 'exists:addresses,id'],

            'pickup_date' => ['nullable', 'date', 'after_or_equal:today'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:pickup_date'],
            'pickup_slot_id' => [
                'nullable',
                Rule::exists('time_slots', 'id')->where(fn ($q) => $q->where('status', 'active')
                    ->whereIn('applies_to', ['pickup', 'both'])),
            ],
            'delivery_slot_id' => [
                'nullable',
                Rule::exists('time_slots', 'id')->where(fn ($q) => $q->where('status', 'active')
                    ->whereIn('applies_to', ['delivery', 'both'])),
            ],

            // «الاستلام من الباب» / «اتركها عند الباب»
            'delivery_method' => ['nullable', Rule::in(['door', 'leave'])],

            'driver_note' => ['nullable', 'string', 'max:1000'],
            'special_instructions' => ['nullable', 'string', 'max:2000'],

            // «أوافق على مراجعة القطع وتحديد السعر النهائي قبل بدء التنظيف».
            // Required, because the whole of P7 rests on it: the customer is
            // agreeing that the price they just saw is an estimate and that the
            // real one arrives after the pieces are counted. `accepted` rather
            // than `boolean` — an unticked box must fail, and `boolean` accepts
            // false.
            'accepts_review_terms' => ['required', 'accepted'],

            'coupon_code' => ['nullable', 'string', 'max:50'],
            // Read off the enum, not hand-listed. The hand-listed version left
            // InstaPay out while the quote endpoint and the payment endpoint both
            // accepted it — so «انستا باي» could be chosen on the payment screen,
            // priced, and then refused at the last step.
            'payment_method' => ['nullable', Rule::in(PaymentMethod::values())],

            // «أضف صوراً للبقع الصعبة»
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pickup_date.after_or_equal' => __('Pickup date cannot be in the past.'),
            'delivery_date.after_or_equal' => __('Delivery cannot be earlier than pickup.'),
            'accepts_review_terms.accepted' => __('Please agree to the piece review and final pricing.'),
            'pickup_slot_id.exists' => __('This pickup window is not available.'),
            'delivery_slot_id.exists' => __('This delivery window is not available.'),
        ];
    }
}
