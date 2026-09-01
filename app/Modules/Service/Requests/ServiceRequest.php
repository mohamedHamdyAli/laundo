<?php

namespace App\Modules\Service\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
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
            'name' => $isUpdate ? 'nullable|array' : 'required|array',
            'name.*' => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',
            'description' => 'nullable|array',
            'description.*' => 'nullable|string|max:1000',
            'image' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
            'pricing_mode' => ($isUpdate ? 'nullable' : 'required').'|in:per_item,quote',
            'duration_min' => 'nullable|integer|min:0|max:9999',
            // A backwards range is a data-entry slip, not a valid turnaround.
            'duration_max' => 'nullable|integer|min:0|max:9999|gte:duration_min',
            'duration_unit' => 'nullable|in:hour,day',
            'sort_order' => 'nullable|integer|min:0',
            'status' => $isUpdate ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'duration_max.gte' => __('The maximum duration must be greater than or equal to the minimum.'),
        ];
    }
}
