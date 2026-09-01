<?php

namespace App\Modules\Zone\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ZoneRequest extends FormRequest
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
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'city_id' => ($isUpdate ? 'nullable' : 'required').'|exists:cities,id',
            'name' => $isUpdate ? 'nullable|array' : 'required|array',
            'name.*' => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',
            // The delivery rate. Nullable on purpose: an unpriced zone makes
            // DeliveryFeeCalculator report that the fee is unknown rather than
            // charge the customer nothing.
            'price_per_km' => 'nullable|numeric|min:0|max:9999.99',
            'min_delivery_fee' => 'nullable|numeric|min:0|max:9999.99',
            'sort_order' => 'nullable|integer|min:0',
            'status' => $isUpdate ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];
    }
}
