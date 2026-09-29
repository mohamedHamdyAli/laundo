<?php

namespace App\Modules\Coupon\Requests;

use App\Modules\Coupon\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
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

            // What it applies to — one kind, several of it. Blank is the whole
            // order. The ids are checked against their own table below.
            'scope_type' => ['nullable', Rule::in(Coupon::SCOPES)],
            'scope_ids' => ['nullable', 'required_with:scope_type', 'array', 'min:1', 'max:200'],
            'scope_ids.*' => ['integer', 'min:1'],

            // Who pays for the discount. `default` follows the general setting,
            // `platform` and `laundry` are 0 and 100, `split` names the laundry's
            // part. Only ever present for somebody holding `setting.update` —
            // see prepareForValidation().
            'discount_bearer' => ['nullable', Rule::in(['default', 'platform', 'laundry', 'split'])],
            'discount_laundry_share' => ['nullable', 'required_if:discount_bearer,split', 'numeric', 'min:0', 'max:100'],

            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_per_user' => [$req, 'integer', 'min:1', 'max:1000'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],

            'status' => [$req, Rule::in(['active', 'inactive'])],
        ];
    }

    /**
     * Who pays for a coupon is a money term, and money terms gate on
     * `setting.update` — the same boundary as a laundry's share. Somebody who may
     * run a campaign but not set what a laundry is paid must not be able to
     * move the campaign's cost onto the laundries, so for them the two fields
     * are dropped before validation and the coupon keeps what it had.
     *
     * Dropped from every bag the input is read from: `validated()` reads
     * `all()`, which merges the query string over the body, so clearing only
     * the form body left `?discount_bearer=laundry` on the URL doing the job.
     */
    protected function prepareForValidation(): void
    {
        if (canDo('setting.update')) {
            return;
        }

        foreach (['discount_bearer', 'discount_laundry_share'] as $key) {
            $this->query->remove($key);
            $this->request->remove($key);

            if ($this->isJson()) {
                $this->json()->remove($key);
            }
        }
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

            $scope = $this->input('scope_type');

            if (in_array($scope, Coupon::SCOPES, true)) {
                $ids = array_map('intval', (array) $this->input('scope_ids', []));
                $table = match ($scope) {
                    Coupon::SCOPE_SERVICE => 'services',
                    Coupon::SCOPE_CATEGORY => 'item_categories',
                    default => 'items',
                };

                if ($ids !== [] && DB::table($table)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
                    $validator->errors()->add('scope_ids', __('Choose from the list — one of these no longer exists.'));
                }

            }

            // A discount on shirts has nothing to do with the journey. Checked
            // against the limit the coupon will have after this save — the one
            // posted, or the stored one when the form or an import sheet did not
            // say — so a sheet row ticking the delivery box cannot reach it.
            $effective = $this->exists('scope_type')
                ? $scope
                : ($this->route('id') ? Coupon::whereKey($this->route('id'))->value('scope_type') : null);

            if (in_array($effective, [Coupon::SCOPE_CATEGORY, Coupon::SCOPE_ITEM], true) && $this->boolean('applies_to_delivery')) {
                $validator->errors()->add('applies_to_delivery', __('A code limited to some pieces cannot also take money off the delivery fee.'));
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
