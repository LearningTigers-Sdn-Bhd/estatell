<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Only an optional step, or Taxes, can be skipped. The lock is checked by SkipOnboardingStep, which refuses it with
 * a message rather than a validation error.
 */
class SkipOnboardingStepRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $afterValidator): void {
            $step = $this->route('step');

            if ($step instanceof OnboardingStep && ! $step->isSkippable()) {
                $afterValidator->errors()->add('step', __(':section is required and cannot be skipped.', ['section' => $step->label()]));
            }
        });
    }
}
