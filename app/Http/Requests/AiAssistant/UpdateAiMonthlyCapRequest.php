<?php

namespace App\Http\Requests\AiAssistant;

use App\Services\AiAssistant\AiMonthlyCap;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiMonthlyCapRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cap_usd' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['cap_usd' => __('monthly cap')];
    }

    /**
     * The cap in micros of a US dollar. Blank is no cap.
     */
    public function capMicros(): ?int
    {
        return app(AiMonthlyCap::class)->toMicros($this->input('cap_usd'));
    }
}
