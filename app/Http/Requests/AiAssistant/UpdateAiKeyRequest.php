<?php

namespace App\Http\Requests\AiAssistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiKeyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'starts_with:sk-', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['key' => __('OpenAI API key')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['key.starts_with' => __('An OpenAI API key starts with sk-.')];
    }
}
