<?php

use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
});

it('resets the reminder stage and records activity after a successful save', function () {
    $this->property->forceFill([
        'onboarding_reminder_stage' => 1,
        'onboarding_last_activity_at' => now()->subDays(2),
    ])->save();

    $this->actingAs($this->owner)
        ->put(route('onboarding.basics.update', $this->property->code), basicsInput())
        ->assertSessionHasNoErrors();

    $this->property->refresh();
    expect($this->property->onboarding_reminder_stage)->toBe(0)
        ->and($this->property->onboarding_last_activity_at->equalTo(now()))->toBeTrue();
});

it('does not record activity for a read request', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertOk();

    expect($this->property->refresh()->onboarding_last_activity_at)->toBeNull();
});

it('does not record activity for a write refused by the onboarding fence', function () {
    $staff = User::factory()->for($this->property)->withPermissions([PermissionEnum::SettingsManage->value])->create();

    $this->actingAs($staff)
        ->put(route('onboarding.basics.update', $this->property->code), basicsInput())
        ->assertRedirect(route('onboarding.waiting', $this->property->code));

    expect($this->property->refresh()->onboarding_last_activity_at)->toBeNull();
});
