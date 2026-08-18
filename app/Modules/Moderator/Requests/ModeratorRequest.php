<?php

namespace App\Modules\Moderator\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModeratorRequest extends FormRequest
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
        $moderatorId = $this->route('id');
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name'          => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',
            'email'         => [
                $isUpdate ? 'nullable' : 'required',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($moderatorId),
            ],
            'phone'         => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'max:191',
                'regex:/^(\+?965)?[569]\d{7}$/',
                Rule::unique('users', 'phone')->ignore($moderatorId),
            ],
            'role_id'       => [
                'required',
                Rule::exists('roles', 'id')->where(function ($query) {
                    $query->where('type', 'dashboard')->where('slug', '!=', 'super_admin');
                }),
            ],
            'image_profile' => ($isUpdate ? 'nullable' : 'required') . '|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
            'password'      => ($isUpdate ? 'nullable' : 'required') . '|string|min:8|confirmed',
            'status'        => 'required|in:active,inactive',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone.regex'   => __('Please enter a valid Kuwaiti phone number.'),
            'role_id.exists' => __('Please select a valid moderator role.'),
        ];
    }
}
