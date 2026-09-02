<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The middle step of a password reset: the code, and nothing else.
 *
 * It carries no password. That is the point of splitting it out — the OTP
 * screen can now answer a wrong code by itself, instead of holding the digits
 * until the password screen submits both together.
 */
class VerifyResetCodeRequest extends FormRequest
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
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
            // Digits only and exactly the configured length, so a malformed
            // guess is rejected before it costs an attempt.
            'code' => ['required', 'string', 'digits:'.(int) config('sms.otp.length', 6)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Please enter a valid Egyptian phone number.'),
        ];
    }
}
