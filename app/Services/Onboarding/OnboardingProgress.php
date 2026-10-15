<?php

namespace App\Services\Onboarding;

use App\Enums\OnboardingPhase;
use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Models\OnboardingSkip;
use App\Models\Property;

/**
 * Works out where a property is in the onboarding wizard. Reads only: the checks read the data, the skips are read
 * from onboarding_skips, and a step is Locked while one of its dependencies is not Done. Nothing is stored.
 */
class OnboardingProgress
{
    /**
     * The state of every step, keyed by the step value, in wizard order.
     *
     * @return array<string, StepState>
     */
    public function states(Property $property): array
    {
        $skipped = array_values(OnboardingSkip::query()
            ->where('property_id', $property->id)
            ->get()
            ->map(fn (OnboardingSkip $skip): string => $skip->step->value)
            ->all());

        $states = [];

        foreach (OnboardingStep::cases() as $step) {
            $states[$step->value] = $this->stateOf($property, $step, $states, $skipped);
        }

        return $states;
    }

    public function state(Property $property, OnboardingStep $step): StepState
    {
        return $this->states($property)[$step->value];
    }

    /**
     * The first dependency of the step that is not Done, or null when the step is open. The server uses it for the
     * "Finish {section} first." message.
     */
    public function blockingStep(Property $property, OnboardingStep $step): ?OnboardingStep
    {
        return $this->blockingStepIn($this->states($property), $step);
    }

    /**
     * The same as blockingStep(), for states that are already calculated.
     *
     * @param  array<string, StepState>  $states
     */
    public function blockingStepIn(array $states, OnboardingStep $step): ?OnboardingStep
    {
        foreach ($step->dependsOn() as $dependency) {
            if ($states[$dependency->value] !== StepState::Done) {
                return $dependency;
            }
        }

        return null;
    }

    /**
     * The state of each phase, keyed by the phase value.
     *
     * @return array<string, StepState>
     */
    public function phaseStates(Property $property): array
    {
        $states = $this->states($property);
        $phaseStates = [];

        foreach (OnboardingPhase::cases() as $phase) {
            $phaseStates[$phase->value] = $this->combine(array_map(
                fn (OnboardingStep $step): StepState => $states[$step->value],
                $phase->steps(),
            ));
        }

        return $phaseStates;
    }

    /**
     * How many required steps are Done, out of how many there are.
     *
     * @return array{done: int, total: int}
     */
    public function requiredCounts(Property $property): array
    {
        $states = $this->states($property);
        $required = $this->requiredSteps();

        return [
            'done' => count(array_filter($required, fn (OnboardingStep $step): bool => $states[$step->value] === StepState::Done)),
            'total' => count($required),
        ];
    }

    /**
     * The estimated minutes left for the required steps that are not Done.
     */
    public function remainingMinutes(Property $property): int
    {
        $states = $this->states($property);

        return array_sum(array_map(
            fn (OnboardingStep $step): int => $step->estimatedMinutes(),
            array_filter($this->requiredSteps(), fn (OnboardingStep $step): bool => $states[$step->value] !== StepState::Done),
        ));
    }

    /**
     * The step the owner should do next: the first required step, in number order, that is unlocked and not Done.
     * Review when every required step is Done.
     */
    public function currentStep(Property $property): OnboardingStep
    {
        $states = $this->states($property);

        foreach ($this->requiredSteps() as $step) {
            if (! in_array($states[$step->value], [StepState::Done, StepState::Locked], true)) {
                return $step;
            }
        }

        return OnboardingStep::Review;
    }

    /**
     * Locked when a dependency is not Done. Done when skipped (optional steps and Taxes only). Otherwise the check.
     *
     * @param  array<string, StepState>  $states  States of the earlier steps, already calculated.
     * @param  list<string>  $skipped
     */
    private function stateOf(Property $property, OnboardingStep $step, array $states, array $skipped): StepState
    {
        foreach ($step->dependsOn() as $dependency) {
            if ($states[$dependency->value] !== StepState::Done) {
                return StepState::Locked;
            }
        }

        if ($step->isSkippable() && in_array($step->value, $skipped, true)) {
            return StepState::Done;
        }

        return $step->check()->state($property);
    }

    /**
     * A phase is Done when all its steps are, Locked when all are Locked, and InProgress when any step has started.
     *
     * @param  list<StepState>  $states
     */
    private function combine(array $states): StepState
    {
        return match (true) {
            in_array(StepState::NeedsAttention, $states, true) => StepState::NeedsAttention,
            array_all($states, fn (StepState $state): bool => $state === StepState::Done) => StepState::Done,
            array_all($states, fn (StepState $state): bool => $state === StepState::Locked) => StepState::Locked,
            in_array(StepState::Done, $states, true), in_array(StepState::InProgress, $states, true) => StepState::InProgress,
            default => StepState::NotStarted,
        };
    }

    /**
     * @return list<OnboardingStep>
     */
    private function requiredSteps(): array
    {
        return array_values(array_filter(OnboardingStep::cases(), fn (OnboardingStep $step): bool => $step->isRequired()));
    }
}
