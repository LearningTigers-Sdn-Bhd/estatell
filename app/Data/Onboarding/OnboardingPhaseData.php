<?php

namespace App\Data\Onboarding;

use App\Enums\OnboardingPhase;
use App\Enums\StepState;
use Spatie\LaravelData\Data;

class OnboardingPhaseData extends Data
{
    /**
     * @param  list<OnboardingStepData>  $steps
     */
    public function __construct(
        public string $value,
        public string $label,
        public StepState $state,
        public array $steps,
    ) {}

    /**
     * @param  list<OnboardingStepData>  $steps
     */
    public static function fromPhase(OnboardingPhase $phase, StepState $state, array $steps): self
    {
        return new self(
            value: $phase->value,
            label: $phase->label(),
            state: $state,
            steps: $steps,
        );
    }
}
