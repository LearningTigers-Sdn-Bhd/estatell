<?php

namespace App\Http\Requests\Admin;

use App\Enums\OnboardingStep;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectGoLiveRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:1000'],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['string', Rule::enum(OnboardingStep::class)->except(OnboardingStep::Review)],
        ];
    }

    /**
     * @return list<OnboardingStep>
     */
    public function flaggedSteps(): array
    {
        return array_map(OnboardingStep::from(...), array_values($this->validated('flags') ?? []));
    }
}
