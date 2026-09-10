<?php

namespace App\Modules\Laundry\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A laundry applying to join, from the public page.
 *
 * Deliberately not `LaundryRequest` with a flag. That request branches on
 * create-versus-update for a signed-in operator; this one is filled in by a
 * stranger, and the two differ in what they may be trusted with rather than
 * only in which fields are required. Nothing here can set `status`, and there
 * is no `id` to ignore on a uniqueness rule — a public form that accepted
 * either would be the whole hole.
 *
 * The rules that *are* shared — the phone format, the uniqueness of a laundry
 * phone, the owner's email — are the same rules on purpose: an application that
 * passes here and then fails the panel's own validation is an application
 * nobody can approve.
 */
class LaundryApplicationRequest extends FormRequest
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
            // At least one language, like every other translatable field in the
            // panel — a laundry writing Arabic only is normal.
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:191'],

            'phone' => ['required', 'string', 'max:191', 'regex:'.phoneRegex(), 'unique:laundries,phone'],
            'email' => ['nullable', 'email', 'max:191', 'unique:laundries,email'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city_id' => ['required', Rule::exists('cities', 'id')->where(fn ($q) => $q->where('status', 'active'))],

            // Delivery fees are measured from the pin, so an application without
            // one produces a laundry that cannot price a delivery. Asked for
            // here rather than chased afterwards.
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],

            'logo' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],

            'owner_name' => ['required', 'string', 'max:191'],
            'owner_email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'owner_phone' => ['required', 'string', 'max:191', 'regex:'.phoneRegex(), 'unique:users,phone'],
            'owner_password' => ['required', 'string', 'min:8', 'confirmed'],

            'accepts_terms' => ['required', 'accepted'],
        ];
    }

    /**
     * At least one language filled, matching how every translatable field in
     * this application is validated.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $names = array_filter((array) $this->input('name', []), fn ($value) => filled($value));

            if ($names === []) {
                $validator->errors()->add('name', __('Please enter the laundry name in at least one language.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
            'owner_phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
            'phone.unique' => __('A laundry with this number has already applied.'),
            'owner_email.unique' => __('An account with this email already exists.'),
            'lat.required' => __('Please pick your laundry on the map.'),
            'lng.required' => __('Please pick your laundry on the map.'),
            'accepts_terms.accepted' => __('Please agree to the terms to continue.'),
        ];
    }
}
