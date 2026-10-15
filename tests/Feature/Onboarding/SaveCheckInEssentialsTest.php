<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Checks\CheckInEssentialsCheck;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->document = $this->property->termsDocuments()->where('is_default', true)->firstOrFail();
    $this->save = fn (array $overrides = [], ?User $user = null) => $this->actingAs($user ?? $this->owner)
        ->put(route('onboarding.check-in-essentials.update', $this->property->code), [
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'registration_card_scope' => 'every_adult',
            'card_required_for_check_in' => true,
            'id_document_retention_days' => '90',
            'collect_deposit' => true,
            'security_deposit_amount' => '150.00',
            ...$overrides,
        ]);
});

test('the check is done with times and published terms and not started without terms', function () {
    $check = new CheckInEssentialsCheck;

    expect($check->state($this->property))->toBe(StepState::Done);

    $this->document->versions()->delete();

    expect($check->state($this->property))->toBe(StepState::NotStarted);
});

test('the page sends the saved settings and the time menus', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.check-in-essentials.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/check-in-essentials')
            ->where('settings.checkInTime', substr($this->property->settings->check_in_time, 0, 5))
            ->where('times.0.value', '00:00')
            ->where('scopes.0.value', 'primary_only')
            ->where('locked', false));
});

test('a save writes the times and card settings and goes to the next section', function () {
    ($this->save)()->assertRedirect(route('onboarding.team.edit', $this->property->code));

    $settings = $this->property->settings->fresh();

    expect(substr($settings->check_in_time, 0, 5))->toBe('14:00')
        ->and(substr($settings->check_out_time, 0, 5))->toBe('12:00')
        ->and($settings->registration_card_scope->value)->toBe('every_adult')
        ->and($settings->id_document_retention_days)->toBe(90)
        ->and($settings->security_deposit_amount)->toBe('150.00');
});

test('turning the deposit off saves 0 and an empty retention keeps the photos', function () {
    ($this->save)(['collect_deposit' => false, 'security_deposit_amount' => '', 'id_document_retention_days' => ''])->assertSessionHasNoErrors();

    $settings = $this->property->settings->fresh();

    expect($settings->security_deposit_amount)->toBe('0.00')
        ->and($settings->id_document_retention_days)->toBeNull();
});

test('a deposit that is on needs an amount above 0', function (?string $amount) {
    ($this->save)(['collect_deposit' => true, 'security_deposit_amount' => $amount])->assertSessionHasErrors('security_deposit_amount');
})->with([[null], ['0'], ['-5']]);

test('the step is locked until Basics is done and a save is refused without writing', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.check-in-essentials.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', OnboardingStep::Basics->label()));

    $this->actingAs($owner)
        ->put(route('onboarding.check-in-essentials.update', $property->code), [
            'check_in_time' => '14:00',
            'check_out_time' => '12:00',
            'registration_card_scope' => 'primary_only',
            'card_required_for_check_in' => true,
            'collect_deposit' => false,
        ])
        ->assertSessionHasErrors('onboarding');
});

test('another property code returns 404', function () {
    $other = Property::factory()->onboarding()->create();

    $this->actingAs($this->owner)
        ->get(route('onboarding.check-in-essentials.edit', $other->code))
        ->assertNotFound();
});
