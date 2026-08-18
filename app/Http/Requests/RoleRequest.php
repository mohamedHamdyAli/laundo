<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
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
        $roleId = $this->route('role');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($roleId),
                function ($attribute, $value, $fail) use ($roleId) {
                    $slug = Str::slug($value);

                    $exists = Role::where('slug', $slug)
                        ->when($roleId, fn ($query) => $query->where('id', '!=', $roleId))
                        ->exists();

                    if ($exists) {
                        $fail(__('A role with this name already exists.'));
                    }
                },
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('A role with this name already exists.'),
        ];
    }
}
