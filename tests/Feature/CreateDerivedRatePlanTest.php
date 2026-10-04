<?php

use App\Actions\Rates\CreateDerivedRatePlan;
use App\Enums\DefaultRoleEnum;
use App\Enums\RateDerivation;
use App\Models\Company;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType, 4: RoomType} */
function quickDerivedSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $parent = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $suite = RoomType::factory()->for($property)->create(['name' => 'Suite']);
    $parent->roomTypes()->attach([$deluxe->id, $suite->id]);

    return [$owner, $property, $parent, $deluxe, $suite];
}

/** @param array<string, mixed> $overrides */
function quickDerivedPayload(RoomType $roomType, array $overrides = []): array
{
    return ['name' => 'Petronas', 'derivation' => 'percent', 'direction' => 'down', 'value' => '15', 'room_type_ids' => [$roomType->id], 'company_ids' => [], ...$overrides];
}

test('it creates a plan that follows the parent on the chosen room types', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();

    $plan = app(CreateDerivedRatePlan::class)($owner, $parent, 'Petronas', RateDerivation::Percent, '-15', [$deluxe->id]);

    expect($plan->parent_rate_plan_id)->toBe($parent->id)
        ->and($plan->derivation)->toBe(RateDerivation::Percent)
        ->and($plan->derivation_value)->toBe('-15.00')
        ->and($plan->pricing_basis)->toBe($parent->pricing_basis)
        ->and($plan->roomTypes()->pluck('room_types.id')->all())->toBe([$deluxe->id])
        ->and(Activity::query()->where('event', 'rate_plan.created')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'rate_plan.derivation_saved')->count())->toBe(1);
});

test('it links the chosen companies and logs each link', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    $companies = Company::factory()->count(2)->for($property)->create();

    $plan = app(CreateDerivedRatePlan::class)($owner, $parent, 'Petronas', RateDerivation::Percent, '-15', [$deluxe->id], $companies->pluck('id')->all());

    expect($plan->companies()->pluck('companies.id')->sort()->values()->all())->toBe($companies->pluck('id')->sort()->values()->all())
        ->and(Activity::query()->where('event', 'company.rates_updated')->count())->toBe(2);
});

test('it writes nothing when the request is refused', function (Closure $arrange) {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    $roomTypeIds = [$deluxe->id];
    $companyIds = [];
    $arrange($property, $parent, $roomTypeIds, $companyIds);
    $before = RatePlan::query()->where('property_id', $property->id)->count();

    $plan = app(CreateDerivedRatePlan::class)($owner, $parent->refresh(), 'Petronas', RateDerivation::Percent, '-15', $roomTypeIds, $companyIds);

    expect($plan)->toBeNull()
        ->and(RatePlan::query()->where('property_id', $property->id)->count())->toBe($before)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'archived parent' => [fn (Property $property, RatePlan $parent) => $parent->update(['archived_at' => now()])],
    'follower parent' => [function (Property $property, RatePlan $parent) {
        $other = RatePlan::factory()->for($property)->create();
        $other->roomTypes()->attach($parent->roomTypes);
        $parent->update(['parent_rate_plan_id' => $other->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-5']);
    }],
    'room type the parent does not sell' => [function (Property $property, RatePlan $parent, array &$roomTypeIds) {
        $roomTypeIds = [RoomType::factory()->for($property)->create()->id];
    }],
    'no room types' => [function (Property $property, RatePlan $parent, array &$roomTypeIds) {
        $roomTypeIds = [];
    }],
    'suspended company' => [function (Property $property, RatePlan $parent, array &$roomTypeIds, array &$companyIds) {
        $companyIds = [Company::factory()->suspended()->for($property)->create()->id];
    }],
    "another property's company" => [function (Property $property, RatePlan $parent, array &$roomTypeIds, array &$companyIds) {
        $companyIds = [Company::factory()->create()->id];
    }],
]);

test('staff create a derived plan and are sent back to the list', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    $company = Company::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe, ['name' => ' Petronas ', 'company_ids' => [$company->id]]))
        ->assertRedirect(route('settings.rate-plans.index', $property->code));

    $plan = RatePlan::query()->where('name', 'Petronas')->sole();
    expect($plan->parent_rate_plan_id)->toBe($parent->id)
        ->and($plan->derivation_value)->toBe('-15.00')
        ->and($plan->companies()->pluck('companies.id')->all())->toBe([$company->id]);
});

test('an up direction is saved as a positive value', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe, ['derivation' => 'amount', 'direction' => 'up', 'value' => '50']));

    expect(RatePlan::query()->where('name', 'Petronas')->sole())->derivation->toBe(RateDerivation::Amount)->derivation_value->toBe('50.00');
});

test('it rejects a taken name, a percent of 100 or more off, and no room types', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe, ['name' => 'basic rate', 'value' => '100', 'room_type_ids' => []]))
        ->assertSessionHasErrors(['name', 'value', 'room_type_ids']);

    expect(RatePlan::query()->where('property_id', $property->id)->count())->toBe(1);
});

test('a refused plan flashes an error toast and writes nothing', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    $parent->update(['archived_at' => now()]);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(RatePlan::query()->where('name', 'Petronas')->exists())->toBeFalse();
});

test('linking companies needs company.manage', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    $company = Company::factory()->for($property)->create();
    $staff = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Rates only']);
    $role->givePermissionTo('settings.manage');
    setPermissionsTeamId($property->id);
    $staff->assignRole($role);

    $this->actingAs($staff)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe, ['company_ids' => [$company->id]]))
        ->assertForbidden();

    $this->actingAs($staff)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe))
        ->assertRedirect();

    expect(RatePlan::query()->where('name', 'Petronas')->exists())->toBeTrue();
});

test('it needs settings.manage and the parent of the property in the URL', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->for($property)->create();
    $foreign = RatePlan::factory()->create();

    $this->actingAs($frontDesk)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $parent]), quickDerivedPayload($deluxe))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.derived.store', [$property->code, $foreign]), quickDerivedPayload($deluxe))
        ->assertNotFound();
});

test('the preview shows the parent price and the derived price for the parent room types', function () {
    [$owner, $property, $parent, $deluxe] = quickDerivedSetup();
    Rate::factory()->create(['rate_plan_id' => $parent->id, 'room_type_id' => $deluxe->id, 'date' => now($property->settings->time_zone)->toDateString(), 'amount' => '800.00']);

    $this->actingAs($owner)
        ->postJson(route('settings.rate-plans.derived.preview', [$property->code, $parent]), ['parent_rate_plan_id' => $parent->id, 'derivation' => 'percent', 'direction' => 'down', 'value' => '15'])
        ->assertOk()
        ->assertJsonPath('0.name', 'Deluxe')
        ->assertJsonPath('0.parentAmount', '800.00')
        ->assertJsonPath('0.derivedAmount', '680.00')
        ->assertJsonPath('1.derivedAmount', null);
});

test('the list offers active companies only to staff who manage companies', function () {
    [$owner, $property] = quickDerivedSetup();
    Company::factory()->for($property)->create(['name' => 'Petronas']);
    Company::factory()->suspended()->for($property)->create(['name' => 'Gone Sdn Bhd']);
    $staff = User::factory()->for($property)->create();
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Rates only']);
    $role->givePermissionTo('settings.manage');
    setPermissionsTeamId($property->id);
    $staff->assignRole($role);

    $this->actingAs($owner)->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('companies', 1)->where('companies.0.name', 'Petronas'));

    $this->actingAs($staff)->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->has('companies', 0));
});
