<?php

namespace App\Modules\Intro\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IntroRequest extends FormRequest
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
                'title' => 'nullable|array',
                'title.*' => 'nullable|string',
                'description' => 'nullable|array',
                'description.*' => 'nullable|string',
                'order' => 'nullable|integer',
                'status' => 'nullable|in:active,inactive',
            ];
        }

        // `title.*`/`description.*` were `required`, which meant an onboarding
        // slide could not be saved until every configured language had copy —
        // so the three designed slides, whose copy exists in Arabic only, were
        // unsaveable.
        //
        // The requirement is «copy in at least one language», not «copy in a
        // particular one». Requiring the default language instead was the
        // obvious fix and it would have changed nothing here: this install's
        // default is `en`, so it would still have demanded the English that
        // does not exist yet — it would only have moved which language blocked
        // the save.
        //
        // At-least-one is also exactly what the read side does. `pickTranslation()`
        // tests each candidate with `filled()` and walks preferred → default →
        // any, so a locale with no copy resolves to a language that has some.
        // The one thing it cannot recover from is an entry with no copy at all,
        // which is the only case this rejects.
        $atLeastOneLanguage = static function (string $attribute, mixed $value, callable $fail): void {
            $hasCopy = is_array($value)
                && collect($value)->contains(static fn ($translation) => filled($translation));

            if (! $hasCopy) {
                $fail(__('Enter this in at least one language.'));
            }
        };

        return [
            'image' => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
            'title' => ['required', 'array', $atLeastOneLanguage],
            'title.*' => 'nullable|string',
            'description' => ['required', 'array', $atLeastOneLanguage],
            'description.*' => 'nullable|string',
            'order' => 'required|integer',
            'status' => 'required|in:active,inactive',
        ];
    }
}
