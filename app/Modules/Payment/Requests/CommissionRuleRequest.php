<?php

namespace App\Modules\Payment\Requests;

use App\Modules\Payment\Models\CommissionRule;
use App\Modules\Payment\Repositories\CommissionRuleRepository;
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

            // The laundry's share of the washing. A percentage and nothing
            // else: the fixed basis was retired when the share moved to the
            // laundry's side, so there is no second box and no basis to choose.
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],

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

            // One active share per laundry. A laundry on two would be paid
            // by whichever the settlement read first, which is terms nobody
            // chose — so the form names the laundries and refuses, rather than
            // quietly moving them off the share they are on.
            if ($this->resultingStatus() !== 'active') {
                return;
            }

            $ids = array_values(array_unique(array_map('intval', (array) $this->input('laundry_ids', []))));
            $clashes = app(CommissionRuleRepository::class)
                ->laundriesOnAnotherShare($ids, $this->ruleId());

            if ($clashes->isNotEmpty()) {
                $validator->errors()->add('laundry_ids', __('These laundries are already on another active share: :names', [
                    'names' => $clashes->map(fn ($laundry) => getLocalizedValueDashboard($laundry, 'name'))->implode('، '),
                ]));
            }
        });
    }

    /**
     * The rule being edited, or null on create.
     */
    private function ruleId(): ?int
    {
        $id = $this->route('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether the rule will be active once saved.
     *
     * An update may leave `status` out, and then the rule keeps the one it has;
     * reading only the input would let an active rule pick up a second
     * laundry's share simply by not resending its status.
     */
    private function resultingStatus(): string
    {
        $sent = $this->input('status');

        if ($sent !== null && $sent !== '') {
            return (string) $sent;
        }

        $id = $this->ruleId();

        return $id === null ? 'active' : (string) CommissionRule::whereKey($id)->value('status');
    }
}
