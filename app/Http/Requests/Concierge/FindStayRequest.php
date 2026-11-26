<?php

namespace App\Http\Requests\Concierge;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FindStayRequest extends FormRequest
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
            'reference' => ['required', 'string', 'max:150'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'for' => ['nullable', 'in:check-out'],
        ];
    }
}
