<?php

namespace App\Modules\Pricing\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One cell of the price grid, as a row: what one item costs under one service.
 *
 * The grid itself posts every cell at once and is validated inline by
 * `PricingController::update()`; this is the same cell on its own, for the Excel
 * import. Same bounds and the same words as the grid's own rule, and the same
 * column set `pricingService::saveGrid()` accepts — an **active per-item**
 * service. A quoted service carries no piece prices by definition, and the grid
 * silently ignores an inactive one; a sheet row naming either is refused here
 * rather than reported as saved.
 */
class ItemPriceRowRequest extends FormRequest
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
        $presence = ($this->isMethod('PUT') || $this->isMethod('PATCH')) ? 'nullable' : 'required';

        return [
            'item_id' => [$presence, 'integer', 'exists:items,id'],
            'service_id' => [
                $presence,
                'integer',
                Rule::exists('services', 'id')->where('pricing_mode', 'per_item')->where('status', 'active'),
            ],
            'price' => [$presence, 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.numeric' => __('Prices must be numbers.'),
            'price.min' => __('Prices cannot be negative.'),
        ];
    }
}
