<?php

namespace App\Http\Requests\AiAssistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiHandoverMessageRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'handover_message' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
