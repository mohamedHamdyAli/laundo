<?php

namespace App\Modules\Order\Requests;

use App\Http\Requests\Api\V1\OrderRequest;

/**
 * «إنشاء طلب» from the panel — the app's own checkout rules, plus whose order it is.
 *
 * Extends the app's request rather than copying it, so a rule added to the
 * checkout (a new discount check, a window rule) reaches the panel the same
 * day. Two differences only:
 *
 * - `user_id`, the customer the operator picked. An integer here; that it is an
 *   active customer is settled in `PanelOrders::customer()`, the same way the
 *   app's address ids are settled through the customer's own relation.
 * - no `prompt_id`: answering a repeat schedule's question is the customer's
 *   own act.
 */
class PanelOrderRequest extends OrderRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['prompt_id']);

        return ['user_id' => ['required', 'integer', 'min:1']] + $rules;
    }

    /**
     * A form cannot post an empty array, so a basket with nothing in it — a
     * quote-priced service — arrives with no `items` at all, which the
     * inherited `present` rule refuses. Read as the empty basket it is; a
     * per-item service with no pieces is still refused, by `OrderService`.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (! $this->has('items')) {
            $this->merge(['items' => []]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'user_id.required' => __('Choose the customer this order is for.'),
            // Said to the operator about the customer, not to the customer.
            'accepts_review_terms.accepted' => __('Confirm that the customer agrees to the piece review and final pricing.'),
        ];
    }
}
