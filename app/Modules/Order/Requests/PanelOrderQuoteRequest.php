<?php

namespace App\Modules\Order\Requests;

use App\Http\Requests\Api\V1\OrderQuoteRequest;

/**
 * The price box on «إنشاء طلب» — the app's quote, plus whose order it is.
 *
 * The windows and dates come with the app's rules (`OrderQuoteRequest`):
 * `OrderService::quote()` reads them to choose the laundry and to see whether a
 * visit is already paid for («رسوم المشوار»), exactly as the submit will.
 */
class PanelOrderQuoteRequest extends OrderQuoteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', 'min:1']] + parent::rules();
    }

    /**
     * An empty basket arrives with no `items` key — see PanelOrderRequest.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->has('items')) {
            $this->merge(['items' => []]);
        }
    }
}
