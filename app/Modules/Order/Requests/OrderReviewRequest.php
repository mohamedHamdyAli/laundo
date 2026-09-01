<?php

namespace App\Modules\Order\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The laundry's count.
 *
 * `qty` may be zero: the review form pre-fills the customer's basket, and a piece
 * that never arrived is recorded by counting it down to nothing rather than by
 * hunting for a delete button. OrderReviewService drops the zeros.
 */
class OrderReviewRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => [
                'required',
                Rule::exists('items', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:999'],
            // Only «تنظيف جاف» and its kind send one. For a catalogued service
            // the value is validated and then ignored by the service, which is
            // deliberate: a posted price must never be able to re-cost an item
            // whose price the platform sets.
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => __('Please enter the pieces you received.'),
            'lines.*.item_id.exists' => __('One of the pieces is no longer available.'),
            'lines.*.unit_price.numeric' => __('Enter a price for every piece you counted.'),
        ];
    }
}
