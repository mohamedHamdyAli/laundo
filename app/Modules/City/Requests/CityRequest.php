<?php

namespace App\Modules\City\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CityRequest extends FormRequest
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
                'name' => 'nullable|array',
                'name.*' => 'nullable|string|max:191',
                'country_id' => 'nullable|exists:countries,id',
                'status' => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'name' => ['required', 'array', $this->atLeastOneLanguage()],
                'name.*' => 'nullable|string|max:191',
                'country_id' => 'required|exists:countries,id',
                'status' => 'required|in:active,inactive',
            ];
        }
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
