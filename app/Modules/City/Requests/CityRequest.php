<?php

namespace App\Modules\City\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CityRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->getMethod() === 'PUT') {
            return [
                'name' => 'nullable|array',
                'name.*' => 'nullable|string|max:191',
                'country_id' => 'nullable|exists:countries,id',
                'status' => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'name' => 'required|array',
                'name.*' => 'required|string|max:191',
                'country_id' => 'required|exists:countries,id',
                'status' => 'required|in:active,inactive',
            ];
        }
    }
}
