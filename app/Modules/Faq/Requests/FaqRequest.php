<?php

namespace App\Modules\Faq\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FaqRequest extends FormRequest
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
        // Required on create, nullable on update — the convention in this project,
        // so an edit that changes only the order does not have to resend the text.
        $isUpdate = $this->getMethod() === 'PUT';

        return [
            'question' => [$isUpdate ? 'nullable' : 'required', 'array'],
            'question.*' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:500'],
            'answer' => [$isUpdate ? 'nullable' : 'required', 'array'],
            'answer.*' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:5000'],
            'audience' => ['nullable', 'in:both,customer,driver'],
            'order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => [$isUpdate ? 'nullable' : 'required', 'in:active,inactive'],
        ];
    }
}
