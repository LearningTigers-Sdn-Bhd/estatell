<?php

use App\Actions\Onboarding\SkipOnboardingStep;
use App\Actions\Onboarding\UnskipOnboardingStep;
use App\Enums\OnboardingStep;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\User;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
});

test('the owner can skip an optional step', function () {
    expect(app(SkipOnboardingStep::class)($this->property, OnboardingStep::Companies, $this->owner))->toBeTrue();

    $skip = OnboardingSkip::query()->where('property_id', $this->property->id)->sole();

    expect($skip->step)->toBe(OnboardingStep::Companies)
        ->and($skip->skipped_by)->toBe($this->owner->id)
        ->and($skip->skipped_at)->not->toBeNull();
});

test('taxes can be skipped as "we do not charge tax"', function () {
    expect(app(SkipOnboardingStep::class)($this->property, OnboardingStep::Taxes, $this->owner))->toBeTrue();
});

test('a required step cannot be skipped and nothing is written', function () {
    expect(app(SkipOnboardingStep::class)($this->property, OnboardingStep::Rooms, $this->owner))->toBeFalse()
        ->and(OnboardingSkip::query()->count())->toBe(0);
});

test('a locked optional step cannot be skipped', function () {
    $fresh = Property::factory()->onboarding()->create();

    expect(app(SkipOnboardingStep::class)($fresh, OnboardingStep::Companies, $this->owner))->toBeFalse()
        ->and(OnboardingSkip::query()->where('property_id', $fresh->id)->exists())->toBeFalse();
});

test('a second skip of the same step is refused and keeps the first row', function () {
    $skip = app(SkipOnboardingStep::class);
    $skip($this->property, OnboardingStep::Companies, $this->owner);

    expect($skip($this->property, OnboardingStep::Companies, $this->owner))->toBeFalse()
        ->and(OnboardingSkip::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('unskipping removes the skip and is refused when there is none', function () {
    app(SkipOnboardingStep::class)($this->property, OnboardingStep::Companies, $this->owner);

    expect(app(UnskipOnboardingStep::class)($this->property, OnboardingStep::Companies))->toBeTrue()
        ->and(OnboardingSkip::query()->where('property_id', $this->property->id)->exists())->toBeFalse()
        ->and(app(UnskipOnboardingStep::class)($this->property, OnboardingStep::Companies))->toBeFalse();
});

test('unskipping never touches another property', function () {
    $other = Property::factory()->onboarding()->create();
    OnboardingSkip::factory()->for($other)->create(['step' => OnboardingStep::Companies]);

    expect(app(UnskipOnboardingStep::class)($this->property, OnboardingStep::Companies))->toBeFalse()
        ->and(OnboardingSkip::query()->where('property_id', $other->id)->exists())->toBeTrue();
});
