<?php

namespace App\Modules\TimeSlot\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TimeSlotRequest extends FormRequest
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
            'start_time' => ($isUpdate ? 'nullable' : 'required').'|date_format:H:i',
            // A window that ends before it starts is never valid.
            'end_time' => ($isUpdate ? 'nullable' : 'required').'|date_format:H:i|after:start_time',
            'applies_to' => 'nullable|in:pickup,delivery,both',
            // Null means unlimited. Zero would mean "never bookable", which is
            // what the status flag already expresses.
            'capacity' => 'nullable|integer|min:1|max:100000',
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
            'end_time.after' => __('The end time must be after the start time.'),
            'capacity.min' => __('Leave capacity empty for unlimited, or enter at least 1.'),
        ];
    }
}
