<?php

use App\Enums\OnboardingStep;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
});

test('onboarding pages share the progress of listed steps only', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/basics')
            ->has('onboarding.phases', 5)
            ->where('onboarding.phases.0.value', 'property')
            ->where('onboarding.phases.0.state', 'in_progress')
            ->has('onboarding.phases.0.steps', 1)
            ->where('onboarding.phases.0.steps.0.value', 'basics')
            ->where('onboarding.phases.0.steps.0.url', route('onboarding.basics.edit', $this->property->code, absolute: false))
            ->where('onboarding.phases.0.steps.0.blockedBy', null)
            ->where('onboarding.phases.1.value', 'rooms')
            ->where('onboarding.phases.1.steps.0.value', 'room_types')
            ->where('onboarding.phases.2.value', 'finance')
            ->has('onboarding.phases.2.steps', 3)
            ->where('onboarding.phases.2.steps.0.value', 'taxes')
            ->where('onboarding.phases.2.steps.1.value', 'payment_methods')
            ->where('onboarding.phases.2.steps.2.value', 'companies')
            ->where('onboarding.phases.3.value', 'prices')
            ->has('onboarding.phases.3.steps', 3)
            ->where('onboarding.phases.3.steps.0.value', 'basic_rate')
            ->where('onboarding.phases.3.steps.1.value', 'charge_items')
            ->where('onboarding.phases.3.steps.2.value', 'discounts')
            ->where('onboarding.phases.4.value', 'review')
            ->has('onboarding.phases.4.steps', 1)
            ->where('onboarding.phases.4.steps.0.value', 'review')
            ->where('onboarding.phases.4.steps.0.url', route('onboarding.go-live.show', $this->property->code, absolute: false))
            ->where('onboarding.requiredDone', 0)
            ->where('onboarding.requiredTotal', 7)
            ->where('onboarding.remainingMinutes', 13));
});

test('a locked listed step names the step it waits for', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.phases.1.steps.0.state', 'locked')
            ->where('onboarding.phases.1.steps.0.blockedBy', 'Basics')
            ->where('onboarding.phases.1.steps.0.blockedByUrl', route('onboarding.basics.edit', $this->property->code, absolute: false)));
});

test('completed basics count as done in the progress', function () {
    completeOnboardingBasics($this->property);

    $this->actingAs($this->owner)
        ->get(route('onboarding.go-live.show', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/go-live')
            ->where('onboarding.requiredDone', 1)
            ->where('onboarding.phases.0.state', 'done')
            ->where('onboarding.phases.0.steps.0.state', 'done')
            ->where('onboarding.phases.1.steps.0.state', 'not_started')
            ->where('onboarding.phases.2.steps.0.state', 'not_started')
            ->where('onboarding.phases.2.steps.1.state', 'not_started')
            ->where('onboarding.phases.2.steps.2.state', 'not_started')
            ->where('onboarding.phases.3.steps.0.state', 'locked'));
});

test('the progress is not shared on a normal PMS page', function () {
    $property = Property::factory()->live()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('settings.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/edit')
            ->missing('onboarding'));
});

test('the progress is not shared on the waiting page, which uses the auth layout', function () {
    $staff = User::factory()->for($this->property)->create();

    $this->actingAs($staff)
        ->get(route('onboarding.waiting', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->missing('onboarding'));
});

test('saving basics opens the next listed step, which is room types', function () {
    $this->actingAs($this->owner)
        ->put(route('onboarding.basics.update', $this->property->code), basicsInput())
        ->assertRedirect(route('onboarding.room-types.edit', $this->property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Property basics saved.']);
});

test('review opens go-live and is the last listed step', function () {
    expect(OnboardingStep::Review->routeName())->toBe('onboarding.go-live.show')
        ->and(OnboardingStep::Review->nextListed())->toBeNull()
        ->and(OnboardingStep::Basics->nextListed())->toBe(OnboardingStep::RoomTypes)
        ->and(OnboardingStep::RoomTypes->nextListed())->toBe(OnboardingStep::Rooms)
        ->and(OnboardingStep::Rooms->nextListed())->toBe(OnboardingStep::Taxes)
        ->and(OnboardingStep::Taxes->nextListed())->toBe(OnboardingStep::PaymentMethods)
        ->and(OnboardingStep::PaymentMethods->nextListed())->toBe(OnboardingStep::Companies)
        ->and(OnboardingStep::Companies->nextListed())->toBe(OnboardingStep::BasicRate)
        ->and(OnboardingStep::BasicRate->nextListed())->toBe(OnboardingStep::ChargeItems)
        ->and(OnboardingStep::ChargeItems->nextListed())->toBe(OnboardingStep::Discounts)
        ->and(OnboardingStep::Discounts->nextListed())->toBe(OnboardingStep::Review)
        ->and(array_map(fn (OnboardingStep $step): OnboardingStep => $step, OnboardingStep::listed()))->toBe([OnboardingStep::Basics, OnboardingStep::RoomTypes, OnboardingStep::Rooms, OnboardingStep::Taxes, OnboardingStep::PaymentMethods, OnboardingStep::Companies, OnboardingStep::BasicRate, OnboardingStep::ChargeItems, OnboardingStep::Discounts, OnboardingStep::Review]);
});
