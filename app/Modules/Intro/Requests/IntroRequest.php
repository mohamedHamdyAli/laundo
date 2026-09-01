<?php

namespace App\Modules\Intro\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IntroRequest extends FormRequest
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
                'image' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'title' => 'nullable|array',
                'title.*' => 'nullable|string',
                'description' => 'nullable|array',
                'description.*' => 'nullable|string',
                'order' => 'nullable|integer',
                'status' => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'image' => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'title' => 'required|array',
                'title.*' => 'required|string',
                'description' => 'required|array',
                'description.*' => 'required|string',
                'order' => 'required|integer',
                'status' => 'required|in:active,inactive',
            ];
        }
    }
}
