<?php

namespace App\Modules\Driver\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «انضم لنا» — three boxes on the public page.
 *
 * Two required and one not, and that is the whole design: a recruitment form
 * asking a courier for a licence number and a vehicle registration on his
 * phone is a form he closes. Everything else is collected in the call that
 * follows, which is what the phone number is for.
 *
 * The phone is **not** held to `phoneRegex()`. Every other number in this
 * application is E.164 because something machine-dials or matches on it; this
 * one is read by a person who is about to ring it, and refusing «01126032991»
 * from somebody volunteering to work for you is a lead thrown away over a
 * plus sign. It is normalised on the way in instead.
 */
class DriverApplicationRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'min:2', 'max:191'],
            // Digits, spaces and the punctuation people actually type. Length
            // bounded so the column cannot be used as a message box.
            'phone' => ['required', 'string', 'min:8', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter a phone number we can call you on.'),
            'name.required' => __('Please tell us your name.'),
            'phone.required' => __('Please leave a number we can reach you on.'),
        ];
    }

    /**
     * Trimmed, and the number reduced to what can be dialled.
     *
     * «0112 603 2991» and «(0112) 603-2991» are the same person; storing them
     * differently makes the list look like two leads.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => preg_replace('/[^\d+]/', '', (string) $this->input('phone')),
            'note' => trim((string) $this->input('note')) ?: null,
        ]);
    }
}
