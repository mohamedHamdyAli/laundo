<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
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
        $length = (int) config('sms.otp.length', 6);

        return [
            'phone' => ['required', 'string', 'regex:'.phoneRegex()],
            // Digits only and exactly the configured length, so a malformed guess
            // is rejected before it costs an attempt.
            'code' => ['required', 'string', 'digits:'.$length],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Enter the number with its country code, e.g. +201012345678.'),
            'code.digits' => __('The code must be :digits digits.', ['digits' => config('sms.otp.length', 6)]),
        ];
    }
}
