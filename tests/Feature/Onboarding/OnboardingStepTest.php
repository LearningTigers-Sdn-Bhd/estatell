<?php

use App\Enums\OnboardingPhase;
use App\Enums\OnboardingStep;
use App\Enums\StepState;
use App\Enums\Tone;

test('the wizard has fourteen steps and the required ones are the rows marked Yes', function () {
    $required = array_values(array_map(
        fn (OnboardingStep $step): string => $step->value,
        array_filter(OnboardingStep::cases(), fn (OnboardingStep $step): bool => $step->isRequired()),
    ));

    expect(OnboardingStep::cases())->toHaveCount(14)
        ->and(OnboardingStep::Basics->number())->toBe(1)
        ->and(OnboardingStep::Review->number())->toBe(14)
        ->and($required)->toBe(['basics', 'room_types', 'rooms', 'taxes', 'payment_methods', 'basic_rate', 'check_in_essentials']);
});

test('a step depends on steps earlier in the wizard', function (OnboardingStep $step, array $dependencies) {
    expect(array_map(fn (OnboardingStep $dependency): OnboardingStep => $dependency, $step->dependsOn()))->toBe($dependencies);
})->with([
    'basics has no prerequisite' => [OnboardingStep::Basics, []],
    'rooms wait for room types' => [OnboardingStep::Rooms, [OnboardingStep::RoomTypes]],
    'room types wait for basics' => [OnboardingStep::RoomTypes, [OnboardingStep::Basics]],
    'basic rate waits for room types and taxes' => [OnboardingStep::BasicRate, [OnboardingStep::RoomTypes, OnboardingStep::Taxes]],
    'charge items wait for taxes' => [OnboardingStep::ChargeItems, [OnboardingStep::Taxes]],
    'discounts wait for basics' => [OnboardingStep::Discounts, [OnboardingStep::Basics]],
]);

test('test drive and review wait for every required step', function (OnboardingStep $step) {
    $required = array_values(array_filter(OnboardingStep::cases(), fn (OnboardingStep $candidate): bool => $candidate->isRequired()));

    expect($step->dependsOn())->toBe($required);
})->with([OnboardingStep::TestDrive, OnboardingStep::Review]);

test('every dependency comes earlier in the wizard, so the order is a valid order to calculate in', function () {
    foreach (OnboardingStep::cases() as $step) {
        foreach ($step->dependsOn() as $dependency) {
            expect($dependency->number())->toBeLessThan($step->number());
        }
    }
});

test('only Taxes and the optional steps can be skipped', function () {
    $skippable = array_values(array_map(
        fn (OnboardingStep $step): string => $step->value,
        array_filter(OnboardingStep::cases(), fn (OnboardingStep $step): bool => $step->isSkippable()),
    ));

    expect($skippable)->toBe(['taxes', 'companies', 'charge_items', 'discounts', 'team', 'channel_manager', 'test_drive', 'review'])
        ->and(OnboardingStep::Basics->isSkippable())->toBeFalse()
        ->and(OnboardingStep::BasicRate->isSkippable())->toBeFalse();
});

test('every step belongs to exactly one phase, in phase order', function () {
    $phases = array_map(fn (OnboardingPhase $phase): array => array_map(fn (OnboardingStep $step): string => $step->value, $phase->steps()), OnboardingPhase::cases());

    expect(array_merge(...$phases))->toHaveCount(14)
        ->and(OnboardingPhase::Finance->steps())->toBe([OnboardingStep::Taxes, OnboardingStep::PaymentMethods, OnboardingStep::Companies])
        ->and(OnboardingPhase::TestDrive->steps())->toBe([OnboardingStep::TestDrive])
        ->and(OnboardingPhase::Review->steps())->toBe([OnboardingStep::Review]);
});

test('basics and review have pages today', function () {
    expect(OnboardingStep::Basics->routeName())->toBe('onboarding.basics.edit')
        ->and(OnboardingStep::RoomTypes->routeName())->toBeNull()
        ->and(OnboardingStep::Review->routeName())->toBe('onboarding.go-live.show');
});

test('step states have a tone that matches their meaning', function (StepState $state, Tone $tone) {
    expect($state->tone())->toBe($tone)
        ->and($state->label())->toBeString()->not->toBe('');
})->with([
    'locked' => [StepState::Locked, Tone::Neutral],
    'not started' => [StepState::NotStarted, Tone::Neutral],
    'in progress' => [StepState::InProgress, Tone::Info],
    'done' => [StepState::Done, Tone::Success],
    'needs attention' => [StepState::NeedsAttention, Tone::Warning],
]);
