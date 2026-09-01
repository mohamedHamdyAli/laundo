<?php

namespace App\Modules\Coupon\Requests;

use App\Modules\Coupon\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
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
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? 'nullable' : 'required';

        return [
            'code' => [
                $req, 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($this->route('id')),
            ],
            'name' => $isUpdate ? 'nullable|array' : 'required|array',
            'name.*' => 'nullable|string|max:191',

            'type' => [$req, Rule::in([Coupon::FIXED, Coupon::PERCENTAGE])],
            'value' => [$req, 'numeric', 'min:0.01'],

            // A percentage over 100 gives money away; a ceiling is what stops a
            // large basket turning a campaign into an incident.
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'min_order_total' => ['nullable', 'numeric', 'min:0'],

            'applies_to_delivery' => ['nullable', 'boolean'],

            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_user' => [$req, 'integer', 'min:1', 'max:1000'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            'status' => [$req, Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * A percentage over 100 gives money away, and the check only means anything
     * for that type — so it lives here rather than in `rules`.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Checked here rather than in `rules`, because the ceiling only means
            // anything for a percentage.
            if ($this->input('type') === Coupon::PERCENTAGE && (float) $this->input('value') > 100) {
                $validator->errors()->add('value', __('A percentage cannot exceed 100.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => __('Use letters, numbers, dashes and underscores only.'),
            'code.unique' => __('This code already exists.'),
            'ends_at.after' => __('The end date must be after the start date.'),
        ];
    }
}
