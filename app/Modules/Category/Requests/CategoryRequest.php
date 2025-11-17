<?php

namespace App\Modules\Category\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->getMethod() === 'PUT') {
            return [
                'image'         => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name'          => 'nullable|array',
                'name.*'        => 'nullable|max:191',
                'parent_id'     => 'nullable|exists:categories,id',
                // 'default_price' => 'nullable|required_with:parent_id|numeric|min:0',
                'status'        => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'image'         => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name'          => 'required|array',
                'name.*'        => 'required|max:191',
                'parent_id'     => 'nullable|exists:categories,id',
                // 'default_price' => 'nullable|required_with:parent_id|numeric|min:0',
                'status'        => 'required|in:active,inactive',
            ];
        }
    }
}
