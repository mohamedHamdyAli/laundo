<?php

namespace App\Modules\Country\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        if ($this->getMethod() === 'PUT') {
            return [
                'name'          => 'nullable|array',
                'name.*'        => 'nullable|max:191',
                'code'          => 'nullable|string|max:5|unique:countries,code,' . $this->route('country'),
                'phone_code'    => 'nullable|string|max:10',
                'status'        => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'name'          => 'required|array',
                'name.*'        => 'required|max:191',
                'code'          => 'required|string|max:5|unique:countries,code',
                'phone_code'    => 'nullable|string|max:10',
                'status'        => 'required|in:active,inactive',
            ];
        }
    }
}
