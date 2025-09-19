<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LanguageRequest extends FormRequest
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
            $languageId = $this->route('id');

            return [
                'name'              => 'required|string|max:100',
                'name_en'           => 'required|string|max:100',
                'code' => [
                    'required',
                    'string',
                    'max:10',
                    Rule::unique('languages', 'code')->ignore($languageId),
                ],
                'country_code'      => 'required|string|max:10',
                'is_rtl'            => 'required|in:true,false',
                'default'            => 'required|in:true,false',
                'icon'              => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'panel_file'        => 'nullable|file|mimes:json,txt',
                'app_file'          => 'nullable|file|mimes:json,txt',
                'app_scope'         => 'nullable|string',
            ];
        } else {
            return [
                'name'              => 'required|string|max:100',
                'name_en'           => 'required|string|max:100',
                'code'              => 'required|string|max:10|unique:languages,code',
                'country_code'      => 'required|string|max:10',
                'is_rtl'            => 'required|in:true,false',
                'default'            => 'required|in:true,false',
                'icon'              => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'panel_file'        => 'nullable|file|mimes:json,txt',
                'app_file'          => 'nullable|file|mimes:json,txt',
                'app_scope'         => 'nullable|string',
            ];
        }
    }
}
