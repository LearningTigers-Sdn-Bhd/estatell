<?php

use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Onboarding\GoLive;
use App\Enums\BusinessDateStatus;
use App\Enums\PropertyStatus;
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function requireGoLiveApproval(bool $required): void
{
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => $required]);
    Cache::forget(PlatformSettings::CACHE_KEY);
}

function onboardingOwner(): User
{
    return User::factory()->owner()->for(Property::factory()->onboarding())->create();
}

test('going live opens the first business date, sets Live and logs it', function () {
    $owner = onboardingOwner();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $property->code), ['opening_date' => '2026-10-08'])
        ->assertRedirect(route('dashboard', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Your property is live.']);

    $businessDate = app(CurrentBusinessDate::class)->get($property);
    $activity = Activity::query()->where('event', 'property.went-live')->sole();

    expect($property->refresh()->status)->toBe(PropertyStatus::Live)
        ->and($property->went_live_at)->not->toBeNull()
        ->and($businessDate->date->toDateString())->toBe('2026-10-08')
        ->and($businessDate->status)->toBe(BusinessDateStatus::Open)
        ->and($activity->causer->is($owner))->toBeTrue()
        ->and($activity->properties->all())->toBe(['from' => 'onboarding', 'to' => 'live', 'opening_date' => '2026-10-08']);
});

test('the platform approval setting sends the property to PendingApproval but still opens the date', function () {
    requireGoLiveApproval(true);
    $owner = onboardingOwner();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $property->code), ['opening_date' => '2026-10-10'])
        ->assertRedirect(route('onboarding.go-live.show', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Submitted for approval.']);

    expect($property->refresh()->status)->toBe(PropertyStatus::PendingApproval)
        ->and($property->went_live_at)->toBeNull()
        ->and(app(CurrentBusinessDate::class)->date($property)->toDateString())->toBe('2026-10-10');

    $this->actingAs($owner)
        ->get(route('onboarding.go-live.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('onboarding/go-live')->where('awaitingApproval', true));
});

test('going live is refused twice and for a property that is not onboarding', function (PropertyStatus $status) {
    $owner = User::factory()->owner()->for(Property::factory()->state(['status' => $status]))->create();

    expect(app(GoLive::class)($owner, $owner->property, CarbonImmutable::parse('2026-10-10')))->toBeNull()
        ->and($owner->property->businessDates()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'property.went-live')->exists())->toBeFalse();
})->with([PropertyStatus::Live, PropertyStatus::PendingApproval]);

test('going live is refused when the property already has a business date', function () {
    $owner = onboardingOwner();
    app(OpenBusinessDate::class)($owner->property, CarbonImmutable::parse('2026-10-01'));

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $owner->property->code), ['opening_date' => '2026-10-10'])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property cannot go live yet.']);

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding);
});

test('the opening date is required, a real date and not in the future', function (?string $date) {
    $owner = onboardingOwner();

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $owner->property->code), ['opening_date' => $date])
        ->assertSessionHasErrors('opening_date');

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding);
})->with([null, 'tomorrow', '2026-10-11', '10/10/2026']);

test('only staff who manage settings can go live, and only on their own property', function () {
    $owner = onboardingOwner();
    $staff = User::factory()->for($owner->property)->create();
    $other = onboardingOwner();

    $this->actingAs($staff)
        ->post(route('onboarding.go-live.store', $owner->property->code), ['opening_date' => '2026-10-10'])
        ->assertRedirect(route('onboarding.waiting', $owner->property->code));

    $pendingProperty = Property::factory()->state(['status' => PropertyStatus::PendingApproval])->create();
    $plainStaff = User::factory()->for($pendingProperty)->create();

    $this->actingAs($plainStaff)
        ->post(route('onboarding.go-live.store', $pendingProperty->code), ['opening_date' => '2026-10-10'])
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $other->property->code), ['opening_date' => '2026-10-10'])
        ->assertNotFound();

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding);
});

test('the go live page sends a live property to the dashboard', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('onboarding.go-live.show', $owner->property->code))
        ->assertRedirect(route('dashboard', $owner->property->code));
});

test('operational modules redirect a property that is not live', function (PropertyStatus $status, string $target) {
    $owner = User::factory()->owner()->for(Property::factory()->state(['status' => $status]))->create();

    $this->actingAs($owner)
        ->get(route('reservations.index', $owner->property->code))
        ->assertRedirect(route($target, $owner->property->code));
})->with([
    'onboarding' => [PropertyStatus::Onboarding, 'onboarding.basics.edit'],
    'pending approval' => [PropertyStatus::PendingApproval, 'onboarding.go-live.show'],
]);

test('settings, the dashboard and the business date send an onboarding property to its first step', function () {
    $owner = onboardingOwner();

    $this->actingAs($owner)->get(route('dashboard', $owner->property->code))->assertRedirect(route('onboarding.basics.edit', $owner->property->code));
    $this->actingAs($owner)->get(route('settings.edit', $owner->property->code))->assertRedirect(route('onboarding.basics.edit', $owner->property->code));
    $this->actingAs($owner)->getJson(route('business-date.show', $owner->property->code))->assertRedirect(route('onboarding.basics.edit', $owner->property->code));
});
