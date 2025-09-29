<?php

namespace App\Modules\Banner\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BannerRequest extends FormRequest
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
                'image'             => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name'              => 'nullable|array',
                'name.*'            => 'nullable|string',
                'description'       => 'nullable|array',
                'description.*'     => 'nullable|string',
                'status'            => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'image'             => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name'              => 'required|array',
                'name.*'            => 'required|string',
                'description'       => 'required|array',
                'description.*'     => 'required|string',
                'status'            => 'required|in:active,inactive',
            ];
        }
    }
}
