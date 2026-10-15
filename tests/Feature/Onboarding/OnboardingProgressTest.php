<?php

use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\OnboardingProgress;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->progress = app(OnboardingProgress::class);
});

/**
 * @param  array<string, StepState>  $states
 * @return array<string, string>
 */
function phaseValues(array $states): array
{
    return array_map(fn (StepState $state): string => $state->value, $states);
}

test('a new property is in progress on basics, and every step after it is locked or not started', function () {
    $property = Property::factory()->onboarding()->create();
    $states = $this->progress->states($property);

    expect($states['basics'])->toBe(StepState::InProgress)
        ->and($states['room_types'])->toBe(StepState::Locked)
        ->and($states['rooms'])->toBe(StepState::Locked)
        ->and($states['taxes'])->toBe(StepState::Locked)
        ->and($states['basic_rate'])->toBe(StepState::Locked)
        ->and($states['review'])->toBe(StepState::Locked);
});

test('basics is done once the address and the settings row are saved', function () {
    $property = Property::factory()->onboarding()->create();
    expect($this->progress->state($property, OnboardingStep::Basics))->toBe(StepState::InProgress);

    completeOnboardingBasics($property);

    expect($this->progress->state($property, OnboardingStep::Basics))->toBe(StepState::Done);
});

test('a step opens once its dependencies are done', function () {
    $property = Property::factory()->onboarding()->create();
    completeOnboardingBasics($property);

    expect($this->progress->state($property, OnboardingStep::RoomTypes))->toBe(StepState::NotStarted)
        ->and($this->progress->state($property, OnboardingStep::Companies))->toBe(StepState::NotStarted)
        ->and($this->progress->state($property, OnboardingStep::Rooms))->toBe(StepState::Locked);
});

test('a skip makes an optional step Done, and Taxes Done only through its skip', function () {
    $property = Property::factory()->onboarding()->create();
    completeOnboardingBasics($property);
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Companies]);
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Taxes]);

    expect($this->progress->state($property, OnboardingStep::Companies))->toBe(StepState::Done)
        ->and($this->progress->state($property, OnboardingStep::Taxes))->toBe(StepState::Done)
        ->and($this->progress->state($property, OnboardingStep::ChargeItems))->toBe(StepState::NotStarted)
        ->and($this->progress->state($property, OnboardingStep::BasicRate))->toBe(StepState::Locked);
});

test('a skip of a step that cannot be skipped is ignored', function () {
    $property = Property::factory()->onboarding()->create();
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Basics]);

    expect($this->progress->state($property, OnboardingStep::Basics))->toBe(StepState::InProgress);
});

test('skips are read only for the property they belong to', function () {
    $property = Property::factory()->onboarding()->create();
    $other = Property::factory()->onboarding()->create();
    completeOnboardingBasics($property);
    OnboardingSkip::factory()->for($other)->create(['step' => OnboardingStep::Companies]);

    expect($this->progress->state($property, OnboardingStep::Companies))->toBe(StepState::NotStarted);
});

test('the current step is the first unlocked required step that is not done', function () {
    $property = Property::factory()->onboarding()->create();

    expect($this->progress->currentStep($property))->toBe(OnboardingStep::Basics);

    completeOnboardingBasics($property);
    expect($this->progress->currentStep($property))->toBe(OnboardingStep::CheckInEssentials);

    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Taxes]);
    expect($this->progress->currentStep($property))->toBe(OnboardingStep::CheckInEssentials);
});

test('required counts and remaining minutes move as steps are done', function () {
    $property = Property::factory()->onboarding()->create();

    expect($this->progress->requiredCounts($property))->toBe(['done' => 0, 'total' => 7])
        ->and($this->progress->remainingMinutes($property))->toBe(13);

    completeOnboardingBasics($property);
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Taxes]);

    expect($this->progress->requiredCounts($property))->toBe(['done' => 2, 'total' => 7])
        ->and($this->progress->remainingMinutes($property))->toBe(10);
});

test('a phase is done when all its steps are, locked when all are locked, and in progress otherwise', function () {
    $property = Property::factory()->onboarding()->create();

    expect(phaseValues($this->progress->phaseStates($property)))
        ->toMatchArray(['property' => 'in_progress', 'rooms' => 'locked', 'finance' => 'locked', 'review' => 'locked']);

    completeOnboardingBasics($property);
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Companies]);

    expect(phaseValues($this->progress->phaseStates($property)))
        ->toMatchArray(['property' => 'in_progress', 'rooms' => 'not_started', 'finance' => 'in_progress']);
});

test('a dependency that is not done blocks a step and is named', function () {
    $property = Property::factory()->onboarding()->create();

    expect($this->progress->blockingStep($property, OnboardingStep::Basics))->toBeNull()
        ->and($this->progress->blockingStep($property, OnboardingStep::Rooms))->toBe(OnboardingStep::RoomTypes)
        ->and($this->progress->blockingStep($property, OnboardingStep::BasicRate))->toBe(OnboardingStep::RoomTypes);

    completeOnboardingBasics($property);
    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Taxes]);

    expect($this->progress->blockingStep($property, OnboardingStep::BasicRate))->toBe(OnboardingStep::RoomTypes);
});

test('basics stays in progress until the settings row exists', function () {
    $owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    expect($this->progress->state($owner->property, OnboardingStep::Basics))->toBe(StepState::InProgress);
});
