<?php

namespace App\Modules\Laundry\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaundryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * On create, the owner account is created in the same transaction, so the
     * owner_* fields are required. On update the laundry is edited on its own —
     * owner accounts are managed through the laundry staff module.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $laundryId = $this->route('id');
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? 'nullable' : 'required';

        $rules = [
            'name' => $isUpdate ? 'nullable|array' : 'required|array',
            'name.*' => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',

            'phone' => [
                $req,
                'string',
                'max:191',
                'regex:'.phoneRegex(),
                Rule::unique('laundries', 'phone')->ignore($laundryId),
            ],
            'email' => [
                'nullable',
                'email',
                'max:191',
                Rule::unique('laundries', 'email')->ignore($laundryId),
            ],
            'address' => 'nullable|string|max:1000',
            'city_id' => 'nullable|exists:cities,id',
            // Delivery fees are measured from here. Nullable rather than required
            // because laundries created before P6 have no pin — but until one is
            // set, their orders cannot be priced for delivery.
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'logo' => ($isUpdate ? 'nullable' : 'nullable').'|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
            'status' => $isUpdate ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];

        if (! $isUpdate) {
            $rules += [
                'owner_name' => 'required|string|max:191',
                'owner_email' => 'required|email|max:191|unique:users,email',
                'owner_phone' => ['required', 'string', 'max:191', 'regex:'.phoneRegex(), 'unique:users,phone'],
                'owner_password' => 'required|string|min:8|confirmed',
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
            'owner_phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
        ];
    }
}
