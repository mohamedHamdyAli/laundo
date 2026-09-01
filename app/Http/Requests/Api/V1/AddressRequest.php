<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirrors the design's "إضافة عنوان جديد" screen.
     *
     * lat/lng are required by business decision: every address carries a map pin
     * so a driver has a point to navigate to, not just a text description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'label' => ['nullable', 'string', 'max:191'],
            'city_id' => ['nullable', 'exists:cities,id'],
            // The zone drives laundry and driver assignment, so it has to be a
            // real, active zone rather than free text.
            'zone_id' => [
                'nullable',
                Rule::exists('zones', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'street' => [$req, 'string', 'max:500'],
            'building' => ['nullable', 'string', 'max:50'],
            'floor' => ['nullable', 'string', 'max:50'],
            'apartment' => ['nullable', 'string', 'max:50'],
            'landmark' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Null means "use the account number", per the design toggle.
            'contact_phone' => ['nullable', 'string', 'regex:'.phoneRegex()],
            'lat' => [$req, 'numeric', 'between:-90,90'],
            'lng' => [$req, 'numeric', 'between:-180,180'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => __('Please enter a valid Egyptian phone number.'),
            'lat.required' => __('Please pick the location on the map.'),
            'lng.required' => __('Please pick the location on the map.'),
        ];
    }
}
