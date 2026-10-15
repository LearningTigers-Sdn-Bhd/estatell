<?php

use App\Enums\PermissionEnum;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    $this->staff = User::factory()->for($this->property)->create();
});

test('the owner of an onboarding property is sent from the dashboard to the first step', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertRedirect(route('onboarding.basics.edit', $this->property->code));
});

test('a fenced page sends the owner to the current step once basics is done', function () {
    completeOnboardingBasics($this->property);

    $this->actingAs($this->owner)
        ->get(route('settings.edit', $this->property->code))
        ->assertRedirect(route('onboarding.check-in-essentials.edit', $this->property->code));
});

test('the owner can open the onboarding pages', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertOk();
});

test('other staff are sent to the waiting page from the fenced pages and from onboarding', function () {
    $this->actingAs($this->staff)
        ->get(route('reservations.index', $this->property->code))
        ->assertRedirect(route('onboarding.basics.edit', $this->property->code));

    $this->actingAs($this->staff)
        ->get(route('onboarding.basics.edit', $this->property->code))
        ->assertRedirect(route('onboarding.waiting', $this->property->code));

    $this->actingAs($this->staff)
        ->put(route('onboarding.basics.update', $this->property->code), ['type' => 'hotel'])
        ->assertRedirect(route('onboarding.waiting', $this->property->code));
});

test('the waiting page tells staff who to ask', function () {
    $this->actingAs($this->staff)
        ->get(route('onboarding.waiting', $this->property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/waiting')
            ->where('ownerName', $this->owner->name));
});

test('a support user viewing the property as staff can set it up', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('view-as.store', $this->property->code), ['user' => $this->staff->id, 'reason' => 'support']);

    $this->get(route('onboarding.basics.edit', $this->property->code))->assertOk();
    $this->get(route('onboarding.waiting', $this->property->code))->assertOk();
});

test('a live property leaves onboarding for its dashboard, for every user', function () {
    $live = User::factory()->owner()->for(Property::factory()->live())->create();
    $liveStaff = User::factory()->for($live->property)->create();

    $this->actingAs($live)
        ->get(route('onboarding.basics.edit', $live->property->code))
        ->assertRedirect(route('dashboard', $live->property->code));

    $this->actingAs($liveStaff)
        ->get(route('onboarding.waiting', $live->property->code))
        ->assertRedirect(route('dashboard', $live->property->code));

    $this->actingAs($live)
        ->get(route('settings.edit', $live->property->code))
        ->assertOk();
});

test('while pending approval, staff are not sent to the waiting page', function () {
    $property = Property::factory()->state(['status' => PropertyStatus::PendingApproval])->create();
    $owner = User::factory()->owner()->for($property)->create();
    $manager = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();
    $plainStaff = User::factory()->for($property)->create();

    $this->actingAs($manager)
        ->get(route('onboarding.go-live.show', $property->code))
        ->assertOk();

    $this->actingAs($plainStaff)
        ->get(route('onboarding.basics.edit', $property->code))
        ->assertOk();

    expect($owner->property_id)->toBe($property->id);
});
