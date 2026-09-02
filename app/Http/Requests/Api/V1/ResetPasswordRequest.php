<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The last step of a password reset: the ticket from `verify-reset-code`, and
 * the new password.
 *
 * It took `phone` + `code` + `password` together, so the OTP screen verified
 * nothing and the code travelled twice — carried across a screen in the app's
 * memory and re-sent with the password. The code is spent at the verify step
 * now; what arrives here is the ticket that step handed back.
 *
 * No `phone`: the ticket identifies the account on its own, and asking for both
 * only creates a way for them to disagree.
 */
class ResetPasswordRequest extends FormRequest
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
        return [
            // 32 random bytes, hex-encoded by the verify step.
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reset_token.required' => __('This password reset is no longer valid. Please request a new code.'),
            'reset_token.size' => __('This password reset is no longer valid. Please request a new code.'),
        ];
    }
}
