<?php

use App\Actions\Property\SaveAgeGroups;
use App\Actions\Property\SeedAgeGroups;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Rates\CreateRatePlan;
use App\Actions\Rates\ReorderRatePlans;
use App\Actions\Rates\SeedRatePlans;
use App\Enums\AgeBandPricing;
use App\Enums\DefaultRoleEnum;
use App\Enums\PricingBasis;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property} */
function ratePlanOwner(): array
{
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();

    return [$owner, $owner->property];
}

test('property defaults seed one Basic Rate plan, priced per room and linked to room-sold room types', function () {
    $property = Property::factory()->create(['country_code' => 'MY']);
    $room = RoomType::factory()->for($property)->create();
    $bed = RoomType::factory()->for($property)->bed()->create();
    $archived = RoomType::factory()->for($property)->archived()->create();

    app(SeedPropertyDefaults::class)($property, PropertyType::Hotel, 'MY');
    app(SeedRatePlans::class)($property);

    $plan = $property->ratePlans()->sole();

    expect($plan->name)->toBe('Basic Rate')
        ->and($plan->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($plan->roomTypes()->pluck('room_types.id')->all())->toBe([$room->id])
        ->and($bed->ratePlans()->count())->toBe(0)
        ->and($archived->ratePlans()->count())->toBe(0);
});

test('seeding keeps plans a property already has', function () {
    $property = Property::factory()->create();
    RatePlan::factory()->for($property)->create(['name' => 'Mine']);

    app(SeedRatePlans::class)($property);

    expect($property->ratePlans()->pluck('name')->all())->toBe(['Mine']);
});

test('the list shows active plans in order with their room types, then archived ones', function () {
    [$owner, $property] = ratePlanOwner();
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe', 'position' => 1]);
    RatePlan::factory()->for($property)->archived()->create(['name' => 'Old', 'position' => 1]);
    $second = RatePlan::factory()->for($property)->perGuest()->create(['name' => 'Second', 'position' => 3]);
    $first = RatePlan::factory()->for($property)->create(['name' => 'First', 'position' => 2]);
    $first->roomTypes()->attach($deluxe);
    RatePlan::factory()->create(['name' => 'Other property']);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/index')
            ->where('ratePlans', fn ($plans) => collect($plans)->pluck('name')->all() === ['First', 'Second', 'Old'])
            ->where('ratePlans.0.roomTypeNames', ['Deluxe'])
            ->where('ratePlans.0.family', 'Room')
            ->where('ratePlans.1.pricingBasis', 'per_guest')
            ->where('ratePlans.2.archived', true)
            ->where('ratePlans.0.status', ['value' => 'active', 'label' => 'Active', 'tone' => 'success'])
            ->where('ratePlans.2.status', ['value' => 'archived', 'label' => 'Archived', 'tone' => 'neutral'])
            ->where('ratePlans.0.isDerived', false)
            ->where('ratePlans.0.familyTone', 'info')
            ->where('ratePlans.0.pricingTone', 'info')
            ->where('ratePlans.1.pricingTone', 'success')
            ->where('statuses', fn ($statuses) => collect($statuses)->pluck('value')->all() === ['active', 'archived'])
            ->where('pricingBases', fn ($bases) => collect($bases)->pluck('value')->all() === ['per_room', 'per_guest', 'per_bed', 'package']));
});

test('owners create a plan at the end of the list and land on its room types tab', function () {
    [$owner, $property] = ratePlanOwner();
    RatePlan::factory()->for($property)->create(['position' => 4]);

    $response = $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), ['name' => ' Breakfast ', 'pricing_basis' => 'per_room'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Breakfast created.']);

    $plan = RatePlan::query()->where('name', 'Breakfast')->sole();

    $response->assertRedirect(route('settings.rate-plans.room-types.edit', [$property->code, $plan]));
    expect($plan->property_id)->toBe($property->id)
        ->and($plan->position)->toBe(5)
        ->and($plan->pricing_basis)->toBe(PricingBasis::PerRoom)
        ->and($plan->roomTypes()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'rate_plan.created')->count())->toBe(1);
});

test('the list page sends active room types with the pricing bases that can sell them', function () {
    [$owner, $property] = ratePlanOwner();
    RoomType::factory()->for($property)->create(['name' => 'Deluxe', 'position' => 1]);
    RoomType::factory()->for($property)->bed()->create(['name' => 'Dorm', 'position' => 2]);
    RoomType::factory()->for($property)->archived()->create(['name' => 'Gone']);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('roomTypes', 2)
            ->where('roomTypes.0.name', 'Deluxe')
            ->where('roomTypes.0.fitsBases', ['per_room', 'per_guest', 'package'])
            ->where('roomTypes.1.fitsBases', ['per_bed', 'package']));
});

test('a plan can be created with room types and then opens on its details tab', function () {
    [$owner, $property] = ratePlanOwner();
    $deluxe = RoomType::factory()->for($property)->create();
    $suite = RoomType::factory()->for($property)->create();

    $response = $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), [
            'name' => 'Breakfast', 'pricing_basis' => 'per_room', 'room_type_ids' => [$deluxe->id, $suite->id],
        ]);

    $plan = RatePlan::query()->where('name', 'Breakfast')->sole();

    $response->assertRedirect(route('settings.rate-plans.edit', [$property->code, $plan]));
    expect($plan->roomTypes()->pluck('room_types.id')->sort()->values()->all())->toBe([$deluxe->id, $suite->id]);
});

test('a new plan cannot sell a room type of the wrong family, another property\'s, or an archived one', function (string $case) {
    [$owner, $property] = ratePlanOwner();
    $roomTypeId = match ($case) {
        'bed room type on a room plan' => RoomType::factory()->for($property)->bed()->create()->id,
        'other property' => RoomType::factory()->create()->id,
        'archived' => RoomType::factory()->for($property)->archived()->create()->id,
    };

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), [
            'name' => 'Breakfast', 'pricing_basis' => 'per_room', 'room_type_ids' => [$roomTypeId],
        ])
        ->assertSessionHasErrors('room_type_ids');

    expect(RatePlan::query()->count())->toBe(0);
})->with(['bed room type on a room plan', 'other property', 'archived']);

test('creating a plan with room types that changed under it is refused without writing', function () {
    [$owner, $property] = ratePlanOwner();
    $bed = RoomType::factory()->for($property)->bed()->create();

    $plan = app(CreateRatePlan::class)($property, $owner, ['name' => 'X', 'pricing_basis' => 'per_room'], [$bed->id]);

    expect($plan)->toBeNull()->and(RatePlan::query()->count())->toBe(0);
});

test('a new per-guest plan starts with the youngest age group free and the others at 50%', function () {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), ['name' => 'Full Board', 'pricing_basis' => 'per_guest']);

    $rows = RatePlan::query()->where('name', 'Full Board')->sole()
        ->childPrices()->with('ageGroup')->get()->sortBy('ageGroup.min_age')->values();

    expect($rows->map(fn ($row) => [$row->ageGroup->label, $row->pricing, $row->value])->all())->toBe([
        ['Infant', AgeBandPricing::Free, null],
        ['Child', AgeBandPricing::Percent, '50.00'],
    ]);
});

test('plan creation is validated', function (array $payload, string $field) {
    [$owner, $property] = ratePlanOwner();
    RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), $payload)
        ->assertSessionHasErrors($field);

    expect(RatePlan::query()->count())->toBe(1);
})->with([
    'name is required' => [['name' => '', 'pricing_basis' => 'per_room'], 'name'],
    'name is unique among active plans, ignoring case' => [['name' => 'basic RATE', 'pricing_basis' => 'per_room'], 'name'],
    'basis is a known value' => [['name' => 'X', 'pricing_basis' => 'per_night'], 'pricing_basis'],
    'a package needs its nights' => [['name' => 'X', 'pricing_basis' => 'package', 'package_unit' => 'per_guest'], 'fixed_nights'],
    'a package has at least one night' => [['name' => 'X', 'pricing_basis' => 'package', 'package_unit' => 'per_guest', 'fixed_nights' => 0], 'fixed_nights'],
    'a package has at most 30 nights' => [['name' => 'X', 'pricing_basis' => 'package', 'package_unit' => 'per_guest', 'fixed_nights' => 31], 'fixed_nights'],
    'a package needs its charging unit' => [['name' => 'X', 'pricing_basis' => 'package', 'fixed_nights' => 2], 'package_unit'],
    'a package unit is a known value' => [['name' => 'X', 'pricing_basis' => 'package', 'fixed_nights' => 2, 'package_unit' => 'per_bed'], 'package_unit'],
]);

test('an archived plan\'s name can be reused', function () {
    [$owner, $property] = ratePlanOwner();
    RatePlan::factory()->for($property)->archived()->create(['name' => 'Promo']);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), ['name' => 'promo', 'pricing_basis' => 'per_room'])
        ->assertSessionHasNoErrors();

    expect($property->ratePlans()->count())->toBe(2);
});

test('each editor tab is its own page and loads only its own data', function () {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');
    $room = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    RoomType::factory()->for($property)->bed()->create(['name' => 'Dorm']);
    RoomType::factory()->for($property)->archived()->create(['name' => 'Gone']);
    $plan = RatePlan::factory()->for($property)->perGuest()->create();
    $plan->roomTypes()->attach($room);
    $infant = $property->ageGroups()->ordered()->first();
    $plan->childPrices()->create(['property_age_group_id' => $infant->id, 'pricing' => AgeBandPricing::Free]);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/edit')
            ->where('ratePlan.roomTypeIds', [$room->id])
            ->missing('roomTypes')
            ->missing('childPrices'));

    $this->get(route('settings.rate-plans.room-types.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/room-types')
            ->where('roomTypes', fn ($choices) => collect($choices)->map(fn ($c) => [$c['name'], $c['fits']])->all() === [['Deluxe', true], ['Dorm', false]])
            ->missing('childPrices'));

    $this->get(route('settings.rate-plans.child-prices.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/child-prices')
            ->has('childPrices', 1)
            ->where('childPrices.0.label', 'Infant')
            ->missing('roomTypes'));
});

test('only per-guest plans have a children tab', function () {
    [$owner, $property] = ratePlanOwner();
    $plan = RatePlan::factory()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.child-prices.edit', [$property->code, $plan]))
        ->assertNotFound();
});

test('details save the name, description and the extra charges that fit the basis', function () {
    [$owner, $property] = ratePlanOwner();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Basic Rate']);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), [
            'name' => 'Room Only', 'description' => 'No meals', 'extra_adult_amount' => '30.00', 'extra_child_amount' => '15',
            'single_supplement' => '99.00',
        ])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room Only saved.']);

    $plan->refresh();

    expect($plan->name)->toBe('Room Only')
        ->and($plan->description)->toBe('No meals')
        ->and($plan->extra_adult_amount)->toBe('30.00')
        ->and($plan->extra_child_amount)->toBe('15.00')
        ->and($plan->single_supplement)->toBe('0.00')
        ->and(Activity::query()->where('event', 'rate_plan.updated')->count())->toBe(1);
});

test('a per-guest plan takes a single supplement and no extra guest charges', function () {
    [$owner, $property] = ratePlanOwner();
    $plan = RatePlan::factory()->for($property)->perGuest()->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), [
            'name' => $plan->name, 'single_supplement' => '40', 'extra_adult_amount' => '30',
        ])
        ->assertSessionHasNoErrors();

    expect($plan->refresh()->single_supplement)->toBe('40.00')->and($plan->extra_adult_amount)->toBe('0.00');
});

test('details are validated and an archived plan cannot be edited', function () {
    [$owner, $property] = ratePlanOwner();
    RatePlan::factory()->for($property)->create(['name' => 'Taken']);
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Mine']);
    $archived = RatePlan::factory()->for($property)->archived()->create(['name' => 'Old']);
    $payload = ['name' => 'Mine', 'extra_adult_amount' => '0', 'extra_child_amount' => '0'];

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), [...$payload, 'name' => 'taken'])
        ->assertSessionHasErrors('name');
    $this->put(route('settings.rate-plans.update', [$property->code, $plan]), [...$payload, 'extra_adult_amount' => '-1'])
        ->assertSessionHasErrors('extra_adult_amount');
    $this->put(route('settings.rate-plans.update', [$property->code, $archived]), [...$payload, 'name' => 'Renamed'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Old is archived. Restore it before editing.']);

    expect($archived->refresh()->name)->toBe('Old');
});

test('room types can be linked and unlinked while each room type keeps an active plan', function () {
    [$owner, $property] = ratePlanOwner();
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $suite = RoomType::factory()->for($property)->create(['name' => 'Suite']);
    $basic = RatePlan::factory()->for($property)->create();
    $breakfast = RatePlan::factory()->for($property)->create();
    $basic->roomTypes()->attach([$deluxe->id, $suite->id]);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $breakfast]), ['room_type_ids' => [$deluxe->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Room types saved.']);
    $this->put(route('settings.rate-plans.room-types.update', [$property->code, $basic]), ['room_type_ids' => [$suite->id]])
        ->assertSessionHasNoErrors();

    expect($breakfast->roomTypes()->pluck('room_types.id')->all())->toBe([$deluxe->id])
        ->and($basic->roomTypes()->pluck('room_types.id')->all())->toBe([$suite->id])
        ->and(Activity::query()->where('event', 'rate_plan.room_types_updated')->count())->toBe(2);
});

test('unlinking the only active plan of a room type is refused with its name', function () {
    [$owner, $property] = ratePlanOwner();
    $dorm = RoomType::factory()->for($property)->bed()->create(['name' => '6-bed Dorm']);
    $plan = RatePlan::factory()->for($property)->perBed()->create();
    $plan->roomTypes()->attach($dorm);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $plan]), ['room_type_ids' => []])
        ->assertSessionHasErrors(['room_type_ids' => '6-bed Dorm would have no rate plan. Link another plan first.']);

    expect($plan->roomTypes()->count())->toBe(1);
});

test('an archived plan does not count as a room type\'s plan', function () {
    [$owner, $property] = ratePlanOwner();
    $room = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    RatePlan::factory()->for($property)->archived()->create()->roomTypes()->attach($room);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($room);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $plan]), ['room_type_ids' => []])
        ->assertSessionHasErrors('room_type_ids');
});

test('a plan cannot sell a room type of the wrong family, another property\'s, or an archived one', function (string $case) {
    [$owner, $property] = ratePlanOwner();
    $plan = RatePlan::factory()->for($property)->create();
    $roomTypeId = match ($case) {
        'bed room type on a room plan' => RoomType::factory()->for($property)->bed()->create()->id,
        'other property' => RoomType::factory()->create()->id,
        'archived' => RoomType::factory()->for($property)->archived()->create()->id,
    };

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $plan]), ['room_type_ids' => [$roomTypeId]])
        ->assertSessionHasErrors('room_type_ids');

    expect($plan->roomTypes()->count())->toBe(0);
})->with(['bed room type on a room plan', 'other property', 'archived']);

test('child prices are saved for every age group and clear the review flag', function () {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');
    [$infant, $child] = $property->ageGroups()->ordered()->get();
    $plan = RatePlan::factory()->for($property)->perGuest()->create(['needs_child_price_review' => true]);
    $plan->childPrices()->create(['property_age_group_id' => $infant->id, 'pricing' => AgeBandPricing::Free]);
    $plan->childPrices()->create(['property_age_group_id' => $child->id, 'pricing' => AgeBandPricing::Free]);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.child-prices.update', [$property->code, $plan]), ['prices' => [
            ['age_group_id' => $infant->id, 'pricing' => 'free', 'value' => '12'],
            ['age_group_id' => $child->id, 'pricing' => 'percent', 'value' => '50'],
        ]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Child prices saved.']);

    $prices = $plan->childPrices()->pluck('value', 'property_age_group_id');

    expect($prices[$infant->id])->toBeNull()
        ->and($prices[$child->id])->toBe('50.00')
        ->and($plan->refresh()->needs_child_price_review)->toBeFalse()
        ->and(Activity::query()->where('event', 'rate_plan.child_prices_updated')->count())->toBe(1);
});

test('child prices are validated', function (array $row, string $field) {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');
    [$infant, $child] = $property->ageGroups()->ordered()->get();
    $plan = RatePlan::factory()->for($property)->perGuest()->create();
    $free = ['age_group_id' => $infant->id, 'pricing' => 'free', 'value' => null];

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.child-prices.update', [$property->code, $plan]), ['prices' => [$free, ['age_group_id' => $child->id, ...$row]]])
        ->assertSessionHasErrors($field);

    expect($plan->childPrices()->count())->toBe(0);
})->with([
    'percent needs a value' => [['pricing' => 'percent', 'value' => null], 'prices.1.value'],
    'percent is at most 100' => [['pricing' => 'percent', 'value' => '150'], 'prices.1.value'],
    'percent is above 0' => [['pricing' => 'percent', 'value' => '0'], 'prices.1.value'],
    'fixed is not negative' => [['pricing' => 'fixed', 'value' => '-5'], 'prices.1.value'],
    'pricing is a known value' => [['pricing' => 'half', 'value' => '5'], 'prices.1.pricing'],
]);

test('child prices must cover exactly the property\'s age groups and only per-guest plans have them', function () {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');
    [$infant] = $property->ageGroups()->ordered()->get();
    $perGuest = RatePlan::factory()->for($property)->perGuest()->create();
    $perRoom = RatePlan::factory()->for($property)->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.child-prices.update', [$property->code, $perGuest]), ['prices' => [
            ['age_group_id' => $infant->id, 'pricing' => 'free', 'value' => null],
        ]])
        ->assertSessionHasErrors('prices');

    $all = $property->ageGroups()->ordered()->get()->map(fn ($group) => ['age_group_id' => $group->id, 'pricing' => 'free', 'value' => null])->all();
    $this->put(route('settings.rate-plans.child-prices.update', [$property->code, $perRoom]), ['prices' => $all])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan or age groups changed. Reload the page and try again.']);

    expect($perRoom->childPrices()->count())->toBe(0);
});

test('a new age group gets a free child price on every per-guest plan and flags them for review', function () {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');
    [$infant, $child] = $property->ageGroups()->ordered()->get();
    $perGuest = RatePlan::factory()->for($property)->perGuest()->create();
    $perRoom = RatePlan::factory()->for($property)->create();

    app(SaveAgeGroups::class)($property, $owner, [
        ['id' => $infant->id, 'label' => 'Infant', 'max_age' => 3],
        ['id' => $child->id, 'label' => 'Child', 'max_age' => 11],
        ['id' => null, 'label' => 'Teen', 'max_age' => 15],
    ]);

    $row = $perGuest->childPrices()->sole();

    expect($row->ageGroup->label)->toBe('Teen')
        ->and($row->pricing)->toBe(AgeBandPricing::Free)
        ->and($perGuest->refresh()->needs_child_price_review)->toBeTrue()
        ->and($perRoom->childPrices()->count())->toBe(0)
        ->and($perRoom->refresh()->needs_child_price_review)->toBeFalse();
});

test('removing an age group removes its child prices', function () {
    [$owner, $property] = ratePlanOwner();
    app(SeedAgeGroups::class)($property, 'MY');
    [$infant, $child] = $property->ageGroups()->ordered()->get();
    $plan = RatePlan::factory()->for($property)->perGuest()->create();
    $plan->childPrices()->create(['property_age_group_id' => $infant->id, 'pricing' => AgeBandPricing::Free]);
    $plan->childPrices()->create(['property_age_group_id' => $child->id, 'pricing' => AgeBandPricing::Free]);

    app(SaveAgeGroups::class)($property, $owner, [['id' => $child->id, 'label' => 'Child', 'max_age' => 11]]);

    expect($plan->childPrices()->pluck('property_age_group_id')->all())->toBe([$child->id]);
});

test('archiving a plan hides it and restoring puts it at the end', function () {
    [$owner, $property] = ratePlanOwner();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Promo', 'position' => 1]);
    RatePlan::factory()->for($property)->create(['position' => 6]);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.archive', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Promo archived.']);

    expect($plan->refresh()->archived_at)->not->toBeNull();

    $this->post(route('settings.rate-plans.restore', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Promo restored.']);

    expect($plan->refresh()->archived_at)->toBeNull()->and($plan->position)->toBe(7)
        ->and(Activity::query()->whereIn('event', ['rate_plan.archived', 'rate_plan.restored'])->count())->toBe(2);
});

test('a plan cannot be archived while a room type depends on it alone', function () {
    [$owner, $property] = ratePlanOwner();
    $room = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($room);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.archive', [$property->code, $plan]))
        ->assertSessionHasErrors(['rate_plan' => 'Deluxe would have no rate plan. Link another plan first.']);

    expect($plan->refresh()->archived_at)->toBeNull()
        ->and(Activity::query()->where('event', 'rate_plan.archived')->count())->toBe(0);

    $other = RatePlan::factory()->for($property)->create();
    $other->roomTypes()->attach($room);

    $this->post(route('settings.rate-plans.archive', [$property->code, $plan]))->assertSessionHasNoErrors();

    expect($plan->refresh()->archived_at)->not->toBeNull();
});

test('a plan cannot be restored while an active plan has its name', function () {
    [$owner, $property] = ratePlanOwner();
    $archived = RatePlan::factory()->for($property)->archived()->create(['name' => 'Promo']);
    RatePlan::factory()->for($property)->create(['name' => 'Promo']);

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.restore', [$property->code, $archived]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Promo cannot be restored. Another rate plan uses the same name.']);

    expect($archived->refresh()->archived_at)->not->toBeNull();
});

test('active plans are reordered and a stale list is refused', function () {
    [$owner, $property] = ratePlanOwner();
    $a = RatePlan::factory()->for($property)->create(['position' => 1]);
    $b = RatePlan::factory()->for($property)->create(['position' => 2]);
    $theirs = RatePlan::factory()->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.reorder', $property->code), ['order' => [$b->id, $a->id]])
        ->assertSessionHasNoErrors();

    expect($b->refresh()->position)->toBe(1)->and($a->refresh()->position)->toBe(2);

    expect(app(ReorderRatePlans::class)($property, $owner, [$b->id, $theirs->id]))->toBeFalse()
        ->and(app(ReorderRatePlans::class)($property, $owner, [$b->id]))->toBeFalse();
});

test('a new room type is linked to the first active plan that fits its sell unit', function () {
    [$owner, $property] = ratePlanOwner();
    RatePlan::factory()->for($property)->archived()->create(['position' => 1]);
    $perBed = RatePlan::factory()->for($property)->perBed()->create(['position' => 2]);
    $perRoom = RatePlan::factory()->for($property)->create(['position' => 3]);
    RatePlan::factory()->for($property)->perGuest()->create(['position' => 4]);

    $payload = fn (string $name, string $unit) => [
        'name' => $name, 'sell_unit' => $unit, 'max_adults' => 2, 'max_children' => 0, 'base_occupancy' => 1,
    ];

    $this->actingAs($owner)
        ->post(route('settings.room-types.store', $property->code), $payload('Deluxe', 'room'))
        ->assertSessionHasNoErrors();
    $this->post(route('settings.room-types.store', $property->code), $payload('Dorm', 'bed'))
        ->assertSessionHasNoErrors();

    expect($perRoom->roomTypes()->pluck('name')->all())->toBe(['Deluxe'])
        ->and($perBed->roomTypes()->pluck('name')->all())->toBe(['Dorm']);
});

test('a room type with no fitting plan stays unlinked', function () {
    [$owner, $property] = ratePlanOwner();
    RatePlan::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('settings.room-types.store', $property->code), [
            'name' => 'Dorm', 'sell_unit' => 'bed', 'max_adults' => 1, 'max_children' => 0, 'base_occupancy' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(RoomType::query()->where('name', 'Dorm')->sole()->ratePlans()->count())->toBe(0);
});

test('changing a room type\'s sell unit swaps its links to a plan that fits', function () {
    [$owner, $property] = ratePlanOwner();
    $perRoom = RatePlan::factory()->for($property)->create(['position' => 1]);
    $perBed = RatePlan::factory()->for($property)->perBed()->create(['position' => 2]);
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $perRoom->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('settings.room-types.update', [$property->code, $roomType]), [
            'name' => 'Deluxe', 'sell_unit' => 'bed', 'max_adults' => 1, 'max_children' => 0, 'base_occupancy' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect($perRoom->roomTypes()->count())->toBe(0)->and($perBed->roomTypes()->pluck('room_types.id')->all())->toBe([$roomType->id]);
});

test('a restored room type is linked again when it has no active plan', function () {
    [$owner, $property] = ratePlanOwner();
    $plan = RatePlan::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->archived()->create();

    $this->actingAs($owner)->post(route('settings.room-types.restore', [$property->code, $roomType]));

    expect($plan->roomTypes()->pluck('room_types.id')->all())->toBe([$roomType->id]);
});

test('database checks reject a bad basis, a negative amount and a child price without a value', function () {
    $plan = RatePlan::factory()->create();

    expect(fn () => DB::table('rate_plans')->where('id', $plan->id)->update(['pricing_basis' => 'per_night']))->toThrow(QueryException::class);
    expect(fn () => RatePlan::factory()->create(['single_supplement' => '-1']))->toThrow(QueryException::class);
    expect(fn () => $plan->childPrices()->create([
        'property_age_group_id' => PropertyAgeGroup::factory()->create(['property_id' => $plan->property_id])->id,
        'pricing' => AgeBandPricing::Percent,
        'value' => null,
    ]))->toThrow(QueryException::class);
});

test('changing rate plans needs settings.manage and stays inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $plan = RatePlan::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.index', $code))->assertForbidden();
    $this->get(route('settings.rate-plans.edit', [$code, $plan]))->assertForbidden();
    $this->get(route('settings.rate-plans.room-types.edit', [$code, $plan]))->assertForbidden();
    $this->get(route('settings.rate-plans.child-prices.edit', [$code, $plan]))->assertForbidden();
    $this->post(route('settings.rate-plans.store', $code), ['name' => 'X', 'pricing_basis' => 'per_room'])->assertForbidden();
    $this->put(route('settings.rate-plans.update', [$code, $plan]), ['name' => 'X'])->assertForbidden();
    $this->put(route('settings.rate-plans.room-types.update', [$code, $plan]), ['room_type_ids' => []])->assertForbidden();
    $this->put(route('settings.rate-plans.child-prices.update', [$code, $plan]), ['prices' => []])->assertForbidden();
    $this->put(route('settings.rate-plans.reorder', $code), ['order' => [$plan->id]])->assertForbidden();
    $this->post(route('settings.rate-plans.archive', [$code, $plan]))->assertForbidden();
    $this->post(route('settings.rate-plans.restore', [$code, $plan]))->assertForbidden();

    [$owner, $property] = ratePlanOwner();
    $theirs = RatePlan::factory()->create(['name' => 'Theirs']);

    $this->actingAs($owner)->get(route('settings.rate-plans.edit', [$property->code, $theirs]))->assertNotFound();
    $this->get(route('settings.rate-plans.room-types.edit', [$property->code, $theirs]))->assertNotFound();
    $this->put(route('settings.rate-plans.update', [$property->code, $theirs]), ['name' => 'Mine'])->assertNotFound();
    $this->post(route('settings.rate-plans.archive', [$property->code, $theirs]))->assertNotFound();
    $this->get(route('settings.rate-plans.index', $theirs->property->code))->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Theirs')->and($theirs->archived_at)->toBeNull();
});

test('the settings sidebar lists rate plans under Rates', function () {
    [$owner, $property] = ratePlanOwner();

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.contexts.2.groups.2.label', 'Rates')
            ->where('navigation.contexts.2.groups.2.items.0.title', 'Rate plans')
            ->where('navigation.contexts.2.groups.2.items.0.active', true));
});
