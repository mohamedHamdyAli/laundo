<?php

namespace App\Modules\Payment\Requests;

use App\Modules\Payment\Enums\CommissionBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CommissionRuleRequest extends FormRequest
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

        return [
            // At least one language, not all of them — a client writing
            // Arabic-only copy is normal. Checked in withValidator().
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:191'],

            'basis' => ['required', 'in:'.implode(',', CommissionBasis::values())],

            // Which of the two applies is decided by the basis, checked below
            // rather than here — a rule cannot express «required only when
            // another field says so» and still name the right box in its message.
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            'laundry_ids' => ['nullable', 'array'],
            'laundry_ids.*' => ['integer', 'exists:laundries,id'],

            'status' => [$isUpdate ? 'nullable' : 'required', 'in:active,inactive'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = $this->input('name', []);

            // `filled()` semantics, so a whitespace-only value is not a name.
            if (is_array($name) && collect($name)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                $validator->errors()->add('name', __('Enter the name in at least one language.'));
            }

            $basis = CommissionBasis::tryFrom((string) $this->input('basis'));

            if ($basis === null) {
                return;
            }

            // The percentage box and the amount box are the same question asked
            // two ways, and the basis decides which is being asked.
            if ($basis->isFixed() && $this->input('amount') === null) {
                $validator->errors()->add('amount', __('Enter the amount this charge takes.'));
            }

            if (! $basis->isFixed() && $this->input('rate') === null) {
                $validator->errors()->add('rate', __('Enter the share of the order.'));
            }
        });
    }
}
