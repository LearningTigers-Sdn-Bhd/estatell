<?php

namespace App\Data\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Models\Property;
use Spatie\LaravelData\Data;

class OnboardingStepData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public int $number,
        public StepState $state,
        public bool $isRequired,
        public bool $isSkippable,
        public ?string $url,
        public ?string $blockedBy,
        public ?string $blockedByUrl,
        public bool $flagged = false,
    ) {}

    /**
     * @param  array<string, StepState>  $states  Every step's state, keyed by the step value.
     */
    public static function fromStep(OnboardingStep $step, Property $property, array $states, ?OnboardingStep $blockingStep, bool $flagged = false): self
    {
        return new self(
            value: $step->value,
            label: $step->label(),
            number: $step->number(),
            state: $states[$step->value],
            isRequired: $step->isRequired(),
            isSkippable: $step->isSkippable(),
            url: $step->routeName() === null ? null : route($step->routeName(), ['property' => $property->code], absolute: false),
            blockedBy: $blockingStep?->label(),
            blockedByUrl: $blockingStep?->routeName() === null ? null : route($blockingStep->routeName(), ['property' => $property->code], absolute: false),
            flagged: $flagged,
        );
    }
}
