<?php

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

test('the owner skips an optional step and lands on the next listed step', function () {
    $this->actingAs($this->owner)
        ->post(route('onboarding.skips.store', [$this->property->code, OnboardingStep::Companies->value]))
        ->assertRedirect(route('onboarding.basic-rate.edit', $this->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Companies skipped.']);

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::Companies)->exists())->toBeTrue();
});

test('a required step cannot be skipped and nothing is written', function () {
    $this->actingAs($this->owner)
        ->post(route('onboarding.skips.store', [$this->property->code, OnboardingStep::Rooms->value]))
        ->assertSessionHasErrors(['step']);

    expect(OnboardingSkip::query()->count())->toBe(0);
});

test('a locked optional step is refused with an error toast and nothing is written', function () {
    $fresh = User::factory()->owner()->for(Property::factory()->onboarding())->create();

    $this->actingAs($fresh)
        ->post(route('onboarding.skips.store', [$fresh->property->code, OnboardingStep::Companies->value]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This section cannot be skipped right now.']);

    expect(OnboardingSkip::query()->where('property_id', $fresh->property_id)->exists())->toBeFalse();
});

test('unskipping removes the skip and stays on the page', function () {
    $this->actingAs($this->owner)
        ->post(route('onboarding.skips.store', [$this->property->code, OnboardingStep::Companies->value]));

    $this->actingAs($this->owner)
        ->delete(route('onboarding.skips.destroy', [$this->property->code, OnboardingStep::Companies->value]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Companies is back on your list.']);

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->exists())->toBeFalse();
});

test('unskipping a step that is not skipped is refused', function () {
    $this->actingAs($this->owner)
        ->delete(route('onboarding.skips.destroy', [$this->property->code, OnboardingStep::Companies->value]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This section is not skipped.']);
});

test('a live property sends the skip route to its dashboard', function () {
    $live = User::factory()->owner()->for(Property::factory()->live())->create();

    $this->actingAs($live)
        ->post(route('onboarding.skips.store', [$live->property->code, OnboardingStep::Companies->value]))
        ->assertRedirect(route('dashboard', $live->property->code));

    expect(OnboardingSkip::query()->where('property_id', $live->property_id)->exists())->toBeFalse();
});

test('other staff cannot skip steps', function () {
    $staff = User::factory()->for($this->property)->create();

    $this->actingAs($staff)
        ->post(route('onboarding.skips.store', [$this->property->code, OnboardingStep::Companies->value]))
        ->assertRedirect(route('onboarding.waiting', $this->property->code));

    expect(OnboardingSkip::query()->count())->toBe(0);
});
