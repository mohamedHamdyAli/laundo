<?php

namespace App\Modules\Item\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ItemRequest extends FormRequest
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
            'item_category_id' => ($isUpdate ? 'nullable' : 'required').'|exists:item_categories,id',
            'name' => $isUpdate ? 'nullable|array' : 'required|array',
            'name.*' => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',
            'image' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
            'sort_order' => 'nullable|integer|min:0',
            'status' => $isUpdate ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];
    }
}
