<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\Property;
use App\Services\Onboarding\OnboardingProgress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Base request for saving one onboarding step. It rejects the save with a validation error while a step it depends
 * on is not Done, so the form shows the error and nothing is written. Use it for every step save.
 */
abstract class OnboardingStepRequest extends FormRequest
{
    /**
     * The step this request saves.
     */
    abstract protected function step(): OnboardingStep;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $afterValidator): void {
            $property = $this->route('property');

            if (! $property instanceof Property) {
                return;
            }

            $blockingStep = app(OnboardingProgress::class)->blockingStep($property, $this->step());

            if ($blockingStep !== null) {
                $afterValidator->errors()->add('onboarding', __('Finish :section first.', ['section' => $blockingStep->label()]));
            }
        });
    }
}
