<?php

namespace App\Modules\Payment\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «خصم» — what is being taken off a laundry, and why.
 *
 * The reason is `required`, not `nullable`, and that is the whole point of the
 * form. A deduction with no reason is a number a laundry can only accept or
 * argue with — the same fault the settlement lines exist to avoid — and it is
 * unanswerable a month later when the operator who typed it has moved on.
 */
class LaundryDeductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is gated on `setting.update`, the same boundary the
        // commission terms use. Repeating it here would let the two drift.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `gt:0` rather than `min:0`: a deduction of nothing is not a
            // deduction, and recording one would put an empty row on a laundry's
            // history with a reason attached to it.
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],

            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.gt' => __('Enter an amount greater than zero.'),
            'reason.required' => __('Say why this is being deducted.'),
            'reason.min' => __('Say why this is being deducted.'),
        ];
    }
}
