<?php

namespace App\Http\Requests\AiAssistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The month the AI assistant Usage tab shows, as `Y-m`. Empty is this month.
 */
class ShowAiUsageRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    public function month(): ?string
    {
        return $this->filled('month') ? $this->string('month')->toString() : null;
    }
}
