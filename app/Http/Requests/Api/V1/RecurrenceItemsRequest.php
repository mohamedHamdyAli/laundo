<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «تحب أخلي دي كمياتك الافتراضية؟» — the basket the schedule asks with.
 *
 * The pieces only. Frequency, weekday and address are the schedule's identity;
 * changing those is still cancel-and-recreate, because a schedule that quietly
 * moved to another day would keep its history while meaning something else.
 */
class RecurrenceItemsRequest extends FormRequest
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
            // Same shape and the same bounds as RecurrenceRequest: a schedule
            // updated through this route must not be able to hold a basket the
            // create route would have refused.
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => [
                'required',
                'integer',
                Rule::exists('items', 'id')->where(fn ($q) => $q->where('status', 'active')),
            ],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.min' => __('Please add at least one piece.'),
        ];
    }
}
