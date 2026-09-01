<?php

namespace App\Modules\User\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class UserRequest extends FormRequest
{
    public function __construct(Request $request)
    {
        $request['role_id'] = '3';
        $request['status'] = 'active';
    }

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
                'image_profile' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name' => 'nullable|max:191',
                'email' => 'nullable|email|max:50|unique:users,email,'.$this->id.',id',
                'phone' => 'nullable|max:191|unique:users,phone,'.$this->id.',id',
                'role_id' => 'required',
                'status' => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'image_profile' => 'required|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
                'name' => 'required|max:191',
                'email' => 'required|email|max:50|unique:users',
                'phone' => 'required|max:191|unique:users',
                'role_id' => 'required',
                'status' => 'required|in:active,inactive',
            ];
        }
    }
}
