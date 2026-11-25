<?php

namespace App\Http\Requests\AiAssistant;

use App\Enums\AiScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiScopesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array:'.implode(',', array_column(AiScope::cases(), 'value'))],
            'scopes.*' => ['required', 'boolean'],
        ];
    }

    /**
     * Each sent scope value with its switch.
     *
     * @return array<string, bool>
     */
    public function scopes(): array
    {
        return array_map(fn (mixed $on): bool => filter_var($on, FILTER_VALIDATE_BOOLEAN), $this->array('scopes'));
    }
}
