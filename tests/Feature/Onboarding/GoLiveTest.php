<?php

use App\Actions\NightAudit\OpenBusinessDate;
use App\Actions\Onboarding\GoLive;
use App\Enums\BusinessDateStatus;
use App\Enums\OnboardingStep;
use App\Enums\PropertyStatus;
use App\Enums\RateDerivation;
use App\Models\OnboardingSkip;
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Onboarding\GoLiveReadiness;
use App\Services\Onboarding\GoLiveWarnings;
use App\Services\Platform\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/OnboardingSetup.php';

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

function readyOnboardingOwner(): User
{
    $owner = onboardingOwner();
    completeRequiredOnboardingSteps($owner->property);

    return $owner;
}

test('the readiness list has the seven required steps, and none is done on a new property', function () {
    $property = onboardingOwner()->property;
    $checks = app(GoLiveReadiness::class)->checks($property);

    expect(array_map(fn ($check): string => $check->key, $checks))
        ->toBe(['basics', 'check_in_essentials', 'room_types', 'rooms', 'taxes', 'payment_methods', 'basic_rate'])
        ->and(collect($checks)->every(fn ($check): bool => ! $check->done))->toBeTrue()
        ->and(app(GoLiveReadiness::class)->isReady($property))->toBeFalse();
});

test('a property with every required step done is ready', function () {
    $owner = readyOnboardingOwner();

    expect(app(GoLiveReadiness::class)->isReady($owner->property))->toBeTrue();
});

test('going live is refused while a required step is not done', function () {
    $owner = onboardingOwner();

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $owner->property->code))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property cannot go live yet.']);

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding)
        ->and(Activity::query()->where('event', 'property.went-live')->exists())->toBeFalse();
});

test('going live flashes wentLive for the dashboard', function () {
    $owner = readyOnboardingOwner();

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $owner->property->code))
        ->assertRedirect(route('dashboard', $owner->property->code))
        ->assertInertiaFlash('wentLive', true);
});

test('going live opens today in the property time zone and ignores any date the form sends', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 17:30:00', 'UTC'));
    $owner = readyOnboardingOwner();
    $property = $owner->property;
    PropertySetting::query()->where('property_id', $property->id)->update(['time_zone' => 'Asia/Kuala_Lumpur']);

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $property->code), ['opening_date' => '2026-09-01'])
        ->assertRedirect(route('dashboard', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Your property is live.']);

    $businessDate = app(CurrentBusinessDate::class)->get($property);
    $activity = Activity::query()->where('event', 'property.went-live')->sole();

    expect($property->refresh()->status)->toBe(PropertyStatus::Live)
        ->and($property->went_live_at)->not->toBeNull()
        ->and($businessDate->date->toDateString())->toBe('2026-10-11')
        ->and($businessDate->status)->toBe(BusinessDateStatus::Open)
        ->and($activity->causer->is($owner))->toBeTrue()
        ->and($activity->properties->all())->toBe(['from' => 'onboarding', 'to' => 'live', 'opening_date' => '2026-10-11']);
});

test('the go live form has no opening date field', function () {
    $owner = readyOnboardingOwner();

    $this->actingAs($owner)
        ->get(route('onboarding.go-live.show', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/go-live')
            ->where('review.ready', true)
            ->missing('today')
            ->missing('checks'));
});

test('the platform approval setting sends the property to PendingApproval but still opens the date', function () {
    requireGoLiveApproval(true);
    $owner = readyOnboardingOwner();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $property->code))
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

    expect(app(GoLive::class)($owner, $owner->property))->toBeNull()
        ->and($owner->property->businessDates()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'property.went-live')->exists())->toBeFalse();
})->with([PropertyStatus::Live, PropertyStatus::PendingApproval]);

test('going live is refused when the property already has a business date', function () {
    $owner = readyOnboardingOwner();
    app(OpenBusinessDate::class)($owner->property, CarbonImmutable::parse('2026-10-01'));

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $owner->property->code))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This property cannot go live yet.']);

    expect($owner->property->refresh()->status)->toBe(PropertyStatus::Onboarding);
});

test('going live refreshes the temp passwords of staff who have not signed in, and leaves the others alone', function () {
    $owner = readyOnboardingOwner();
    $property = $owner->property;

    $pending = User::factory()->for($property)->withTemporaryPassword('Kopi-1111-Meja')->create();
    $signedIn = User::factory()->for($property)->create(['must_change_password' => false, 'temporary_password' => null, 'temporary_password_expires_at' => null]);
    $deactivated = User::factory()->for($property)->withTemporaryPassword('Teh-2222-Pasir')->deactivated()->create();

    $this->actingAs($owner)->post(route('onboarding.go-live.store', $property->code));

    $pending->refresh();

    expect($property->refresh()->status)->toBe(PropertyStatus::Live)
        ->and($pending->temporary_password)->not->toBe('Kopi-1111-Meja')
        ->and($pending->temporary_password_expires_at->toDateTimeString())->toBe(now()->addDays(14)->toDateTimeString())
        ->and($pending->must_change_password)->toBeTrue()
        ->and(Hash::check($pending->temporary_password, $pending->password))->toBeTrue()
        ->and($signedIn->refresh()->temporary_password)->toBeNull()
        ->and($deactivated->refresh()->temporary_password)->toBe('Teh-2222-Pasir');
});

test('the temp password refresh does not put any password in the activity log', function () {
    $owner = readyOnboardingOwner();
    $property = $owner->property;
    User::factory()->for($property)->withTemporaryPassword('Kopi-3333-Meja')->create();

    $this->actingAs($owner)->post(route('onboarding.go-live.store', $property->code));

    $newPassword = User::query()->where('property_id', $property->id)->where('must_change_password', true)->sole()->temporary_password;

    expect(Activity::query()->where('event', 'staff.temp-password-refreshed')->count())->toBe(1)
        ->and(Activity::query()->whereRaw('properties::text like ?', ["%{$newPassword}%"])->exists())->toBeFalse()
        ->and(Activity::query()->whereRaw('properties::text like ?', ['%Kopi-3333-Meja%'])->exists())->toBeFalse();
});

test('warnings name the section that fixes them and never block going live', function () {
    $owner = readyOnboardingOwner();
    $property = $owner->property;
    Tax::factory()->for($property)->create(['name' => 'Service charge']);

    $warnings = app(GoLiveWarnings::class)->forProperty($property);

    expect(collect($warnings)->contains(fn ($warning): bool => $warning->step === OnboardingStep::ChargeItems->value
        && $warning->message === 'Service charge is not applied to any charge item.'))->toBeTrue();

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $property->code))
        ->assertRedirect(route('dashboard', $property->code));

    expect($property->refresh()->status)->toBe(PropertyStatus::Live);
});

test('a tax applied only to rooms is not flagged as unused', function () {
    $property = readyOnboardingOwner()->property;

    expect(collect(app(GoLiveWarnings::class)->forProperty($property))
        ->contains(fn ($warning): bool => $warning->step === OnboardingStep::ChargeItems->value))->toBeFalse();
});

test('a property with no tax on rooms gets a warning, and skipping Taxes removes it', function () {
    $owner = onboardingOwner();
    $property = $owner->property;

    expect(collect(app(GoLiveWarnings::class)->forProperty($property))->contains(fn ($warning): bool => $warning->step === OnboardingStep::Taxes->value
        && $warning->message === 'No tax is applied to rooms.'))->toBeTrue();

    OnboardingSkip::factory()->for($property)->create(['step' => OnboardingStep::Taxes]);

    expect(collect(app(GoLiveWarnings::class)->forProperty($property))->contains(fn ($warning): bool => $warning->step === OnboardingStep::Taxes->value))->toBeFalse();
});

test('a follower rate plan that misses a room type of its parent gets a warning', function () {
    $owner = readyOnboardingOwner();
    $property = $owner->property;
    $parent = RatePlan::factory()->for($property)->create(['name' => 'Long stay']);
    $missing = RoomType::factory()->for($property)->create(['name' => 'Garden Suite']);
    $parent->roomTypes()->attach([RoomType::query()->where('property_id', $property->id)->value('id'), $missing->id]);

    $follower = RatePlan::factory()->for($property)->create([
        'name' => 'Long stay monthly',
        'parent_rate_plan_id' => $parent->id,
        'derivation' => RateDerivation::Percent,
        'derivation_value' => '-10',
    ]);
    $follower->roomTypes()->attach(RoomType::query()->where('property_id', $property->id)->where('id', '!=', $missing->id)->value('id'));

    $warnings = collect(app(GoLiveWarnings::class)->forProperty($property))
        ->filter(fn ($warning): bool => $warning->step === OnboardingStep::BasicRate->value)
        ->pluck('message')
        ->all();

    expect($warnings)->toBe(['Long stay monthly does not include Garden Suite.']);
});

test('the review page lists the sections by phase with their actions and the counts', function () {
    $owner = onboardingOwner();
    $property = $owner->property;
    User::factory()->for($property)->withTemporaryPassword()->count(2)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.go-live.show', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/go-live')
            ->where('review.ready', false)
            ->where('review.signInCount', 2)
            ->where('review.firstBusinessDate', '10 Oct 2026')
            ->has('review.missing', 7)
            ->has('review.phases', 5)
            ->where('review.phases.0.value', 'property')
            ->where('review.phases.0.rows.0.value', 'basics')
            ->where('review.phases.0.rows.0.state', 'in_progress')
            ->where('review.phases.0.rows.0.actionLabel', 'Finish →')
            ->where('review.phases.0.rows.1.value', 'check_in_essentials')
            ->where('review.phases.0.rows.1.state', 'locked')
            ->where('review.phases.0.rows.1.actionUrl', route('onboarding.basics.edit', $property->code, absolute: false))
            ->where('review.phases.0.rows.2.value', 'team')
            ->where('review.phases.0.rows.2.isRequired', false));
});

test('only staff who manage settings can go live, and only on their own property', function () {
    $owner = readyOnboardingOwner();
    $staff = User::factory()->for($owner->property)->create();
    $other = readyOnboardingOwner();

    $this->actingAs($staff)
        ->post(route('onboarding.go-live.store', $owner->property->code))
        ->assertRedirect(route('onboarding.waiting', $owner->property->code));

    $pendingProperty = Property::factory()->state(['status' => PropertyStatus::PendingApproval])->create();
    $plainStaff = User::factory()->for($pendingProperty)->create();

    $this->actingAs($plainStaff)
        ->post(route('onboarding.go-live.store', $pendingProperty->code))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('onboarding.go-live.store', $other->property->code))
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
