<?php

namespace App\Modules\Faq\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaqRequest extends FormRequest
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
        // Required on create, nullable on update — the convention in this project,
        // so an edit that changes only the order does not have to resend the text.
        $isUpdate = $this->getMethod() === 'PUT';
        $required = $isUpdate ? 'nullable' : 'required';

        return [
            /*
             * At least one language, not all of them.
             *
             * These were `question.*` and `answer.*` => `required`, which demanded
             * **every** language before a FAQ could be saved. It went unnoticed
             * only because the form rendered a single box for the default
             * language, so there was never a second value to leave empty — the
             * moment the other languages were added to the form, saving an
             * English-only FAQ would have been refused.
             *
             * This is the rule the rest of the translatable modules use (Offer,
             * Banner, Intro, JourneyStep) and the one the read path assumes:
             * `pickTranslation()` walks preferred -> default -> any non-empty, so
             * a FAQ written in one language displays fine in the other.
             */
            'question' => [$required, 'array', $this->atLeastOneLanguage()],
            'question.*' => ['nullable', 'string', 'max:500'],
            'answer' => [$required, 'array', $this->atLeastOneLanguage()],
            'answer.*' => ['nullable', 'string', 'max:5000'],

            'audience' => ['nullable', 'in:both,customer,driver'],
            'order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => [$required, 'in:active,inactive'],
        ];
    }

    /**
     * Refuse a translatable field whose every language is blank.
     *
     * `filled()` rather than a truthiness test, so a box holding only spaces does
     * not count as having been written in — the same check `pickTranslation()`
     * applies when reading.
     */
    private function atLeastOneLanguage(): callable
    {
        return static function (string $attribute, mixed $value, callable $fail): void {
            $hasOne = is_array($value)
                && collect($value)->contains(static fn ($translation) => filled($translation));

            if (! $hasOne) {
                $fail(__('Fill this in for at least one language.'));
            }
        };
    }
}
