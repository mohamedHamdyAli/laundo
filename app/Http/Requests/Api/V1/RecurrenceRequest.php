<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A repeat schedule: «كل أسبوع» / «كل أسبوعين» / «كل شهر».
 *
 * `day_of_week` is ISO (1 = Monday … 7 = Sunday) and required for the weekly
 * frequencies — «كل يوم اثنين» needs a weekday to mean anything. Monthly does
 * not: it repeats from its own start date.
 */
class RecurrenceRequest extends FormRequest
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
            'service_id' => [
                'required',
                Rule::exists('services', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'pickup_address_id' => ['required', 'integer', 'exists:addresses,id'],
            'time_slot_id' => [
                'nullable',
                Rule::exists('time_slots', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],

            'frequency' => ['required', Rule::in(['weekly', 'biweekly', 'monthly'])],
            'day_of_week' => [
                'nullable',
                'required_unless:frequency,monthly',
                'integer', 'between:1,7',
            ],

            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],

            'starts_on' => ['nullable', 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.required_unless' => __('Please choose which day of the week to repeat on.'),
            'starts_on.after' => __('A schedule cannot start today or earlier.'),
        ];
    }
}
