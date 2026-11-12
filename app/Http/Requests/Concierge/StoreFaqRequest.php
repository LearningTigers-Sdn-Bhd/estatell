<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'topic' => ['nullable', 'string', 'max:100'],
            'question' => ['required', 'string', 'max:200', 'regex:/\S/'],
            'answer' => ['required', 'string', 'max:1000', 'regex:/\S/'],
        ];
    }
}
