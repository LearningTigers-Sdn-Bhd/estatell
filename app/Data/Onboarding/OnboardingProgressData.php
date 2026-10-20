<?php

namespace App\Data\Onboarding;

use App\Enums\OnboardingPhase;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Services\Onboarding\GoLiveRequests;
use App\Services\Onboarding\OnboardingProgress;
use Spatie\LaravelData\Data;

/**
 * The wizard progress for the onboarding layout: the phase bar, the steppers and the top bar counts. Only steps with
 * a page are listed, and a phase without one is left out. Shared on onboarding pages only.
 */
class OnboardingProgressData extends Data
{
    /**
     * @param  list<OnboardingPhaseData>  $phases
     */
    public function __construct(
        public array $phases,
        public int $requiredDone,
        public int $requiredTotal,
        public int $remainingMinutes,
        public bool $awaitingApproval = false,
    ) {}

    public static function forProperty(Property $property, OnboardingProgress $progress, GoLiveRequests $requests): self
    {
        $flagged = $requests->flaggedSteps($property);
        $states = $progress->states($property);
        $phaseStates = $progress->phaseStates($property);
        $requiredCounts = $progress->requiredCounts($property);

        $phases = [];

        foreach (OnboardingPhase::cases() as $phase) {
            $steps = [];

            foreach ($phase->steps() as $step) {
                $steps[] = OnboardingStepData::fromStep(
                    $step,
                    $property,
                    $states,
                    $progress->blockingStepIn($states, $step),
                    in_array($step->value, $flagged, true),
                );
            }

            if ($steps !== []) {
                $phases[] = OnboardingPhaseData::fromPhase($phase, $phaseStates[$phase->value], $steps);
            }
        }

        return new self(
            phases: $phases,
            requiredDone: $requiredCounts['done'],
            requiredTotal: $requiredCounts['total'],
            remainingMinutes: $progress->remainingMinutes($property),
            awaitingApproval: $property->status === PropertyStatus::PendingApproval,
        );
    }
}
