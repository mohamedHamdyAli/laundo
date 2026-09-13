<?php

namespace App\Modules\Driver\Requests;

use App\Modules\Driver\Enums\BonusBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DriverBonusRuleRequest extends FormRequest
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
            // Arabic-only copy is normal.
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:191'],

            'basis' => ['required', 'in:'.implode(',', BonusBasis::values())],

            // Which of the two applies is decided by the basis, checked in
            // withValidator() below rather than here — a rule cannot express
            // "required only when another field says so" and still give a
            // message that names the right box.
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Every gate is optional, and empty means «not applied».
            'min_on_time_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_delivery_rating' => ['nullable', 'numeric', 'min:1', 'max:5'],
            'max_failed_tasks' => ['nullable', 'integer', 'min:0', 'max:1000'],

            'tier_min_orders' => ['nullable', 'array'],
            'tier_min_orders.*' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'tier_amounts' => ['nullable', 'array'],
            'tier_amounts.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],

            'status' => [$isUpdate ? 'nullable' : 'required', 'in:active,inactive'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $name = $this->input('name', []);

            // At least one language filled. `filled()` semantics, so a
            // whitespace-only value does not count as a name.
            if (is_array($name) && collect($name)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) {
                $validator->errors()->add('name', __('Enter the name in at least one language.'));
            }

            $basis = BonusBasis::tryFrom((string) $this->input('basis'));

            if ($basis === null) {
                return;
            }

            // The amount box and the percentage box are the same question asked
            // two ways, and the basis decides which one is being asked.
            if ($basis->isFlat() && $this->input('amount') === null) {
                $validator->errors()->add('amount', __('Enter the amount this rule pays.'));
            }

            if (! $basis->isFlat() && $this->input('rate') === null) {
                $validator->errors()->add('rate', __('Enter the share of the delivery fee.'));
            }

            // A tier is a pair. Half of one is a row somebody started, and
            // saving it silently as «0 orders for 0 pounds» is worse than
            // saying so.
            $mins = $this->input('tier_min_orders', []) ?? [];
            $amounts = $this->input('tier_amounts', []) ?? [];

            foreach ($mins as $index => $min) {
                $amount = $amounts[$index] ?? null;
                $hasMin = $min !== null && $min !== '';
                $hasAmount = $amount !== null && $amount !== '';

                if ($hasMin !== $hasAmount) {
                    $validator->errors()->add(
                        "tier_min_orders.{$index}",
                        __('A target needs both a number of orders and an amount.')
                    );
                }
            }
        });
    }
}
