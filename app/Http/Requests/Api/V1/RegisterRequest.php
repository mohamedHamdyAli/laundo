<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The design's register screen: name, phone, optional email, zone, password
     * with confirmation, and a terms checkbox.
     *
     * `unique:users,phone` is what implements the decision to refuse a number
     * that is already taken, verified or not.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['required', 'string', 'max:191', 'regex:'.phoneRegex(), 'unique:users,phone'],
            // Optional in the design, but must still be unique when supplied.
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'accepted_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Please enter a valid Egyptian phone number.'),
            'phone.unique' => __('This phone number is already registered.'),
            'accepted_terms.accepted' => __('You must accept the terms and privacy policy.'),
        ];
    }
}
