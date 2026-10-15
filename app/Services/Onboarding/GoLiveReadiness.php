<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\GoLiveCheckData;
use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Models\Property;

/**
 * The required onboarding steps a property must finish before it can go live: one check per required step, in
 * wizard order. The state comes from OnboardingProgress, so a step is Done here exactly when the wizard shows it Done.
 */
class GoLiveReadiness
{
    public function __construct(private OnboardingProgress $progress) {}

    /**
     * @return list<GoLiveCheckData>
     */
    public function checks(Property $property): array
    {
        $states = $this->progress->states($property);

        return array_values(array_map(
            fn (OnboardingStep $step): GoLiveCheckData => new GoLiveCheckData(
                key: $step->value,
                label: $step->label(),
                done: $states[$step->value] === StepState::Done,
            ),
            array_filter(OnboardingStep::cases(), fn (OnboardingStep $step): bool => $step->isRequired()),
        ));
    }

    public function isReady(Property $property): bool
    {
        return collect($this->checks($property))->every(fn (GoLiveCheckData $check): bool => $check->done);
    }
}
