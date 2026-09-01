<?php

namespace App\Modules\Banner\Requests;

use App\Modules\Banner\Enums\BannerTarget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BannerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->getMethod() === 'PUT') {
            return [
                'image' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name' => 'nullable|array',
                'name.*' => 'nullable|string',
                'description' => 'nullable|array',
                'description.*' => 'nullable|string',
                'status' => 'nullable|in:active,inactive',
                'target_type' => 'nullable|in:'.implode(',', BannerTarget::values()),
                'target_value' => 'nullable|string|max:255',
            ];
        } else {
            return [
                'image' => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name' => 'required|array',
                'name.*' => 'required|string',
                'description' => 'required|array',
                'description.*' => 'required|string',
                'status' => 'required|in:active,inactive',
                'target_type' => 'nullable|in:'.implode(',', BannerTarget::values()),
                'target_value' => 'nullable|string|max:255',
            ];
        }
    }

    /**
     * A target kind that needs a value must have one.
     *
     * Per-column rules cannot say this, and without it a banner saves with a
     * button pointing nowhere — which is the exact bug these columns were added
     * to fix, reintroduced one layer up.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $type = BannerTarget::tryFrom((string) $this->input('target_type', 'none'));

            if ($type !== null && $type->needsValue() && blank($this->input('target_value'))) {
                $validator->errors()->add(
                    'target_value',
                    __('Choose what the banner should open.')
                );
            }
        });
    }
}
