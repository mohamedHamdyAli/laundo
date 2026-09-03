<?php

namespace App\Modules\Offer\Requests;

use App\Modules\Offer\Enums\OfferTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class OfferRequest extends FormRequest
{
    /**
     * Permissions are enforced by the route's `permission:` middleware, not
     * here — the same division every module in this panel uses.
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

            // «Copy in at least one language», not «copy in every language».
            // The latter is what `IntroRequest` used to say, and it made the
            // designed cards — whose copy exists in Arabic only — unsaveable.
            // It matches the read side: `pickTranslation()` walks preferred →
            // default → any, so a locale with no copy resolves to one that has
            // some. The only thing it cannot recover from is an entry with no
            // copy at all, which is the one case this rejects.
            'title' => [$req, 'array', $this->atLeastOneLanguage()],
            'title.*' => 'nullable|string|max:191',
            'description' => [$req, 'array', $this->atLeastOneLanguage()],
            'description.*' => 'nullable|string',

            'coupon_id' => 'nullable|exists:coupons,id',
            'target_type' => 'nullable|in:'.implode(',', OfferTarget::values()),
            'target_value' => 'nullable|string|max:255',

            'starts_at' => 'nullable|date',
            // Same rule `CouponRequest` uses on the same pair of columns.
            'ends_at' => 'nullable|date|after:starts_at',

            'sort_order' => 'nullable|integer|min:0',
            'status' => $update ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];
    }

    /**
     * A closure rather than a rule object: it is used twice, in one class, and
     * a whole file for it would be indirection with nothing behind it.
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $type = OfferTarget::tryFrom((string) $this->input('target_type', 'none'));

            if ($type !== null && $type->needsValue() && blank($this->input('target_value'))) {
                $validator->errors()->add(
                    'target_value',
                    __('Choose what the offer should open.')
                );
            }
        });
    }
}
