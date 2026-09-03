<?php

namespace App\Modules\LaundryStaff\Requests;

use App\Support\LaundryContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LaundryStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staffId = $this->route('id');
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name' => $isUpdate ? 'nullable|string|max:191' : 'required|string|max:191',
            'email' => [
                $isUpdate ? 'nullable' : 'required',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($staffId),
            ],
            'phone' => [
                $isUpdate ? 'nullable' : 'required',
                'string',
                'max:191',
                'regex:'.phoneRegex(),
                Rule::unique('users', 'phone')->ignore($staffId),
            ],
            // Only roles of type `laundry` may be assigned here, so a laundry
            // owner cannot escalate their staff to a dashboard or super admin role.
            'role_id' => [
                $isUpdate ? 'nullable' : 'required',
                Rule::exists('roles', 'id')->where(fn ($query) => $query->where('type', 'laundry')),
            ],
            // A laundry user's own laundry is forced by BelongsToLaundry and the
            // field is ignored; a super admin has no laundry context and must say
            // which laundry the account belongs to.
            'laundry_id' => [
                LaundryContext::isTenant() ? 'nullable' : ($isUpdate ? 'nullable' : 'required'),
                'exists:laundries,id',
            ],
            'image_profile' => 'nullable|image|mimes:jpg,png,jpeg,gif,svg|max:2048',
            'password' => ($isUpdate ? 'nullable' : 'required').'|string|min:8|confirmed',
            'status' => $isUpdate ? 'nullable|in:active,inactive' : 'required|in:active,inactive',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
            'role_id.exists' => __('Please select a valid laundry role.'),
        ];
    }
}
