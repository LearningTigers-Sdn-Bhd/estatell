<?php

use App\Actions\BookingEngine\SetUpBookingSite;
use App\Enums\CompanyAccess;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingEngine\BookingSiteChecklist;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner of a property with the Booking engine on and no rate plans.
 *
 * @return array{owner: User, property: Property}
 */
function bookingRatesSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(BookingEngine::class);
    RatePlan::query()->where('property_id', $property->id)->delete();

    return compact('owner', 'property');
}

function putRatePlanSwitch(Property $property, User $user, RatePlan $plan, bool $bookable)
{
    return test()->actingAs($user)->put(route('settings.booking-website.rates.update', $property), ['rate_plan_id' => $plan->id, 'bookable' => $bookable]);
}

it('lists only active nightly plans open to everyone, with their room types', function () {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    $shown = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);
    $shown->roomTypes()->attach(RoomType::factory()->for($property)->create(['name' => 'Deluxe King']));
    RatePlan::factory()->for($property)->create(['name' => 'Corporate', 'company_access' => CompanyAccess::All]);
    RatePlan::factory()->for($property)->dayUse()->create(['name' => 'Day pass']);
    RatePlan::factory()->for($property)->archived()->create(['name' => 'Old plan']);
    RatePlan::factory()->create(['name' => 'Other property plan']);

    $this->actingAs($owner)->get(route('settings.booking-website.rates', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/booking-website/rates')
            ->has('ratePlans', 1)
            ->where('ratePlans.0.name', 'Basic Rate')
            ->where('ratePlans.0.bookableOnline', false)
            ->where('ratePlans.0.roomTypeNames', ['Deluxe King']));
});

it('turns a plan on and off and logs who did it', function () {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    $plan = RatePlan::factory()->for($property)->create();

    putRatePlanSwitch($property, $owner, $plan, true)->assertRedirect();
    expect($plan->refresh()->bookable_online)->toBeTrue()
        ->and(Activity::query()->where('event', 'rate_plan.bookable_online_changed')->where('causer_id', $owner->id)->count())->toBe(1);

    putRatePlanSwitch($property, $owner, $plan, true)->assertRedirect();
    expect(Activity::query()->where('event', 'rate_plan.bookable_online_changed')->count())->toBe(1);

    putRatePlanSwitch($property, $owner, $plan, false)->assertRedirect();
    expect($plan->refresh()->bookable_online)->toBeFalse();
});

it('refuses plans it does not list, and writes nothing', function (Closure $makePlan) {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    $plan = $makePlan($property);

    putRatePlanSwitch($property, $owner, $plan, true)->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');

    expect($plan->refresh()->bookable_online)->toBeFalse()
        ->and(Activity::query()->where('event', 'rate_plan.bookable_online_changed')->exists())->toBeFalse();
})->with([
    'company-only' => [fn (Property $property) => RatePlan::factory()->for($property)->create(['company_access' => CompanyAccess::All])],
    'day use' => [fn (Property $property) => RatePlan::factory()->for($property)->dayUse()->create()],
    'archived' => [fn (Property $property) => RatePlan::factory()->for($property)->archived()->create()],
    'another property' => [fn (Property $property) => RatePlan::factory()->create()],
]);

it('keeps the last bookable plan on while the site is published', function () {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    $first = RatePlan::factory()->for($property)->bookableOnline()->create();
    $second = RatePlan::factory()->for($property)->bookableOnline()->create();
    $site = app(SetUpBookingSite::class)($property);

    $site->forceFill(['published_at' => now()])->save();

    putRatePlanSwitch($property, $owner, $first, false)->assertRedirect();
    expect($first->refresh()->bookable_online)->toBeFalse();

    putRatePlanSwitch($property, $owner, $second, false)->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');
    expect($second->refresh()->bookable_online)->toBeTrue();

    $site->forceFill(['published_at' => null])->save();

    putRatePlanSwitch($property, $owner, $second, false)->assertRedirect();
    expect($second->refresh()->bookable_online)->toBeFalse();
});

it('adds the public rate plan row to the checklist', function () {
    ['property' => $property] = bookingRatesSetup();
    $site = app(SetUpBookingSite::class)($property);
    $row = fn () => collect(app(BookingSiteChecklist::class)->items($site->refresh()))->firstWhere('key', 'rates');

    expect($row()->done)->toBeFalse();

    $plan = RatePlan::factory()->for($property)->bookableOnline()->create();
    expect($row()->done)->toBeTrue();

    $plan->forceFill(['company_access' => CompanyAccess::All])->save();
    expect($row()->done)->toBeFalse();
});

it('needs the manage permission and the feature, and respects the property code', function () {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();
    $plan = RatePlan::factory()->for($property)->create();

    $this->actingAs($staff)->get(route('settings.booking-website.rates', $property))->assertForbidden();
    putRatePlanSwitch($property, $staff, $plan, true)->assertForbidden();

    $this->actingAs($owner)->get(route('settings.booking-website.rates', Property::factory()->create()))->assertNotFound();

    Feature::for($property)->deactivate(BookingEngine::class);
    $this->actingAs($owner)->get(route('settings.booking-website.rates', $property))->assertNotFound();
});

it('shows the book online switch on the Rate plans list only with the feature and the permission', function () {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    RatePlan::factory()->for($property)->bookableOnline()->create(['name' => 'Basic Rate']);
    RatePlan::factory()->for($property)->dayUse()->create(['name' => 'Day pass']);
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();

    $this->actingAs($owner)->get(route('settings.rate-plans.index', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onlineBooking', true)
            ->where('ratePlans', fn ($plans) => collect($plans)->firstWhere('name', 'Basic Rate')['bookableOnline'] === true
                && collect($plans)->firstWhere('name', 'Basic Rate')['offerableOnline'] === true
                && collect($plans)->firstWhere('name', 'Day pass')['offerableOnline'] === false));

    $plan = RatePlan::query()->where('property_id', $property->id)->where('name', 'Basic Rate')->firstOrFail();
    $this->actingAs($owner)->get(route('settings.rate-plans.edit', [$property, $plan]))
        ->assertInertia(fn (Assert $page) => $page->where('onlineBooking', true)->where('ratePlan.bookableOnline', true));

    $this->actingAs($staff)->get(route('settings.rate-plans.index', $property))
        ->assertInertia(fn (Assert $page) => $page->where('onlineBooking', false));
    $this->actingAs($staff)->get(route('settings.rate-plans.edit', [$property, $plan]))
        ->assertInertia(fn (Assert $page) => $page->where('onlineBooking', false));

    Feature::for($property)->deactivate(BookingEngine::class);
    $this->actingAs($owner)->get(route('settings.rate-plans.index', $property))
        ->assertInertia(fn (Assert $page) => $page->where('onlineBooking', false));
});

it('saves the book online switch with the rate plan details', function () {
    ['owner' => $owner, 'property' => $property] = bookingRatesSetup();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();
    $details = fn (bool $bookable) => ['name' => 'Basic Rate', 'description' => null, 'extra_adult_amount' => '0.00', 'extra_child_amount' => '0.00', 'bookable_online' => $bookable];

    $this->actingAs($owner)->put(route('settings.rate-plans.update', [$property, $plan]), $details(true))->assertRedirect();
    expect($plan->refresh()->bookable_online)->toBeTrue();

    $this->actingAs($staff)->put(route('settings.rate-plans.update', [$property, $plan]), $details(false))->assertRedirect();
    expect($plan->refresh()->bookable_online)->toBeTrue();

    app(SetUpBookingSite::class)($property)->forceFill(['published_at' => now()])->save();
    $this->actingAs($owner)->put(route('settings.rate-plans.update', [$property, $plan]), [...$details(false), 'name' => 'Renamed'])
        ->assertRedirect()->assertInertiaFlash('toast.type', 'error');
    expect($plan->refresh()->bookable_online)->toBeTrue()->and($plan->name)->toBe('Basic Rate');
});
