<?php

namespace App\Modules\JourneyStep\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JourneyStepRequest extends FormRequest
{
    /**
     * Permissions are enforced by the route's `permission:` middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $update = $this->getMethod() === 'PUT';
        $req = $update ? 'nullable' : 'required';

        return [
            'image' => $update
                ? 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048'
                : 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',

            // «Copy in at least one language», not «in every language» — the
            // rule the Intro and Offer requests now share, and for the same
            // reason: the designed copy exists in Arabic only, and requiring
            // every configured language made it unsaveable. It matches the read
            // side, where `pickTranslation()` walks preferred → default → any.
            'title' => [$req, 'array', $this->atLeastOneLanguage()],
            'title.*' => 'nullable|string|max:191',
            'description' => [$req, 'array', $this->atLeastOneLanguage()],
            'description.*' => 'nullable|string',

            'sort_order' => 'nullable|integer|min:0',
            'status' => $update ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];
    }

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
