<?php

namespace App\Modules\Country\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CountryRequest extends FormRequest
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
        $countryId = $this->route('country');

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            return [
                'name'          => 'nullable|array|min:1',
                'name.*'        => 'nullable|string|max:191',
                'code'          => [
                    'nullable',
                    'string',
                    'max:5',
                    Rule::unique('countries', 'code')->ignore($countryId)
                ],
                'phone_code'    => 'nullable|string|max:10',
                'status'        => 'nullable|in:active,inactive',
            ];
        }

        return [
            'name'          => 'required|array|min:1',
            'name.*'        => 'required|string|max:191',
            'code'          => 'required|string|max:5|unique:countries,code',
            'phone_code'    => 'nullable|string|max:10',
            'status'        => 'nullable|in:active,inactive',
        ];
    }
}
