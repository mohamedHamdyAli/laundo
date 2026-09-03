<?php

namespace App\Modules\Country\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CountryRequest extends FormRequest
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
        $countryId = $this->route('country');

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            return [
                'name' => 'nullable|array|min:1',
                'name.*' => 'nullable|string|max:191',
                'code' => [
                    'nullable',
                    'string',
                    'max:5',
                    Rule::unique('countries', 'code')->ignore($countryId),
                ],
                'phone_code' => 'nullable|string|max:10',
                'timezone' => 'nullable|string|max:64',
                'status' => 'nullable|in:active,inactive',
            ];
        }

        return [
            'name' => ['required', 'array', $this->atLeastOneLanguage()],
            'name.*' => 'nullable|string|max:191',
            'code' => 'required|string|max:5|unique:countries,code',
            'phone_code' => 'nullable|string|max:10',
            'timezone' => 'nullable|string|max:64',
            'status' => 'nullable|in:active,inactive',
        ];
    }

    /**
     * «Copy in at least one language», not «copy in every language».
     *
     * The rule was `required` per language key, which made content that exists
     * in one language — which is how the designed copy exists — impossible to
     * save. It also contradicted the read path: `pickTranslation()` walks
     * preferred → default → any, so a locale with no copy resolves to one that
     * has some. The only thing it cannot recover from is an entry with no copy
     * at all, which is the one case this rejects.
     */
    private function atLeastOneLanguage(): callable
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            $hasCopy = is_array($value)
                && collect($value)->contains(static fn ($translation) => filled($translation));

            if (! $hasCopy) {
                $fail(__('Enter this in at least one language.'));
            }
        };
    }
}
