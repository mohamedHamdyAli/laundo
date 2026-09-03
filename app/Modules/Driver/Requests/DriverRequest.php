<?php

namespace App\Modules\Driver\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Driver accounts are created administratively — the design's driver login
     * screen offers no registration, only «تواصل مع المشرف» — so this is the only
     * place a driver comes into existence.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $driverId = $this->route('id');
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:191'],
            'phone' => [
                $isUpdate ? 'nullable' : 'required',
                'string', 'max:191', 'regex:'.phoneRegex(),
                Rule::unique('users', 'phone')->ignore($driverId),
            ],
            'email' => ['nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($driverId)],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'image_profile' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],
            'status' => [$isUpdate ? 'nullable' : 'required', 'in:active,inactive'],

            // Vehicle and documents — بيانات المركبة / رخصة القيادة / مستندات المركبة
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'plate_number' => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'license_expiry' => ['nullable', 'date'],
            'license_image' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],
            'vehicle_registration_image' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],
            'vehicle_registration_expiry' => ['nullable', 'date'],
            'national_id_image' => ['nullable', 'image', 'mimes:jpg,png,jpeg,gif,svg', 'max:2048'],

            // Both columns were added in P6 and never given a field, so every
            // driver has null for both — and the dispatch rules P8 enforces are
            // built on exactly them. Still nullable: an unset cap means uncapped
            // and an unset city does not disqualify, so a half-configured driver
            // keeps working rather than silently dropping out of dispatch.
            'max_concurrent_orders' => ['nullable', 'integer', 'min:1', 'max:50'],
            'city_id' => ['nullable', 'exists:cities,id'],

            // أوقات العمل — one window per driver, applied to every day.
            'shift_start' => ['nullable', 'date_format:H:i'],
            'shift_end' => ['nullable', 'date_format:H:i', 'after:shift_start'],

            'is_available' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // المناطق التي أخدمها — must be real, active zones, since dispatch
            // matches an order's zone against these.
            'zones' => ['array'],
            'zones.*' => [
                'integer',
                Rule::exists('zones', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
            'shift_end.after' => __('The shift end must be after the shift start.'),
            'zones.*.exists' => __('One of the selected areas is not available.'),
        ];
    }
}
