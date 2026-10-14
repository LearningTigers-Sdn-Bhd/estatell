<?php

use App\Actions\Property\SaveAgeGroups;
use App\Actions\Property\SeedAgeGroups;
use App\Enums\DefaultRoleEnum;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Packages\PackagePriceOverlap;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType} */
function packageSetup(PackageUnit $unit = PackageUnit::PerGuest): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->package($unit)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    return [$owner, $property, $plan, $roomType];
}

/** @return array<string, mixed> */
function packageRow(RoomType $roomType, array $overrides = []): array
{
    return [
        'id' => null,
        'room_type_id' => $roomType->id,
        'arrival_from' => '2026-06-01',
        'arrival_to' => '2026-06-30',
        'amount' => '600',
        ...$overrides,
    ];
}

function packageUrl(Property $property, RatePlan $plan): string
{
    return route('settings.rate-plans.pricing.package.update', [$property->code, $plan]);
}

const ALL_WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

test('a package plan is created with its nights and unit and opens on its room types tab', function () {
    [$owner, $property] = packageSetup();

    $response = $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), ['name' => 'Island Escape', 'pricing_basis' => 'package', 'fixed_nights' => 2, 'package_unit' => 'per_room'])
        ->assertSessionHasNoErrors();

    $plan = RatePlan::query()->where('name', 'Island Escape')->sole();

    $response->assertRedirect(route('settings.rate-plans.room-types.edit', [$property->code, $plan]));
    expect($plan->pricing_basis)->toBe(PricingBasis::Package)
        ->and($plan->fixed_nights)->toBe(2)
        ->and($plan->package_unit)->toBe(PackageUnit::PerRoom);
});

test('nights and unit are ignored on a plan that is not a package', function () {
    [$owner, $property] = packageSetup();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), ['name' => 'Breakfast', 'pricing_basis' => 'per_room', 'fixed_nights' => 2, 'package_unit' => 'per_guest'])
        ->assertSessionHasNoErrors();

    $plan = RatePlan::query()->where('name', 'Breakfast')->sole();

    expect($plan->fixed_nights)->toBeNull()->and($plan->package_unit)->toBeNull();
});

test('a package can sell room and bed room types', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();
    $bed = RoomType::factory()->for($property)->bed()->create();

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.room-types.update', [$property->code, $plan]), ['room_type_ids' => [$roomType->id, $bed->id]])
        ->assertSessionHasNoErrors();

    expect($plan->roomTypes()->count())->toBe(2);
});

test('the pricing tab of a package lists its prices, the room types it sells and every weekday when none are stored', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();
    RoomType::factory()->for($property)->create();
    $archived = RoomType::factory()->for($property)->archived()->create();
    $plan->roomTypes()->attach($archived);
    PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-07-01', 'arrival_to' => '2026-07-31', 'amount' => '750.00']);
    PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30']);

    $this->actingAs($owner)
        ->get(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))
        ->assertInertia(fn (Assert $page) => $page->component('settings/rate-plans/package-pricing')
            ->where('ratePlan.fixedNights', 2)
            ->where('ratePlan.packageUnit', 'per_guest')
            ->has('roomTypes', 1)
            ->where('roomTypes.0.id', $roomType->id)
            ->has('prices', 2)
            ->where('prices.0.arrivalFrom', '2026-06-01')
            ->where('prices.1.amount', '750.00')
            ->where('weekdays', ALL_WEEKDAYS)
            ->has('weekdayOptions', 7));
});

test('a package cannot extend nightly prices', function () {
    [$owner, $property, $plan] = packageSetup();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.pricing.extend', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => $plan->name.' cannot extend its prices. Reload the page and try again.']);
});

test('prices and weekdays are saved as one list: added, changed and removed rows', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();
    $kept = PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30', 'amount' => '600.00']);
    $removed = PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-09-01', 'arrival_to' => '2026-09-30']);

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), [
            'prices' => [
                packageRow($roomType, ['id' => $kept->id, 'amount' => '650.5']),
                packageRow($roomType, ['arrival_from' => '2026-07-01', 'arrival_to' => '2026-07-31', 'amount' => '750']),
            ],
            'weekdays' => ['friday', 'saturday'],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Package pricing saved.']);

    $rows = $plan->packagePrices()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->toBe($kept->id)
        ->and($rows[0]->amount)->toBe('650.50')
        ->and($rows[1]->arrival_from->toDateString())->toBe('2026-07-01')
        ->and(PackagePrice::query()->whereKey($removed->id)->exists())->toBeFalse()
        ->and($plan->arrivalWeekdays()->get()->pluck('weekday.value')->sort()->values()->all())->toBe(['friday', 'saturday'])
        ->and(Activity::query()->where('event', 'rate_plan.package_pricing_updated')->count())->toBe(1);
});

test('changing the weekdays replaces them', function () {
    [$owner, $property, $plan] = packageSetup();
    $plan->arrivalWeekdays()->create(['weekday' => 'friday']);
    $plan->arrivalWeekdays()->create(['weekday' => 'saturday']);

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [], 'weekdays' => ['saturday', 'sunday']])
        ->assertSessionHasNoErrors();

    expect($plan->arrivalWeekdays()->get()->pluck('weekday.value')->sort()->values()->all())->toBe(['saturday', 'sunday']);
});

test('all seven weekdays are stored as none, which means any day', function () {
    [$owner, $property, $plan] = packageSetup();
    $plan->arrivalWeekdays()->create(['weekday' => 'friday']);

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [], 'weekdays' => ALL_WEEKDAYS])
        ->assertSessionHasNoErrors();

    expect($plan->arrivalWeekdays()->count())->toBe(0);
});

test('a save that changes nothing is not logged', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();
    $price = PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30', 'amount' => '600.00']);

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [packageRow($roomType, ['id' => $price->id])], 'weekdays' => ALL_WEEKDAYS])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Package pricing saved.']);

    expect(Activity::query()->where('event', 'rate_plan.package_pricing_updated')->count())->toBe(0);
});

test('package pricing is validated', function (array $row, string $field) {
    [$owner, $property, $plan, $roomType] = packageSetup();

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [packageRow($roomType, $row)], 'weekdays' => ALL_WEEKDAYS])
        ->assertSessionHasErrors("prices.0.{$field}");

    expect($plan->packagePrices()->count())->toBe(0);
})->with([
    'no arrival start' => [['arrival_from' => null], 'arrival_from'],
    'a bad date' => [['arrival_from' => '1 June'], 'arrival_from'],
    'an end before the start' => [['arrival_to' => '2026-05-31'], 'arrival_to'],
    'no price' => [['amount' => null], 'amount'],
    'a negative price' => [['amount' => '-1'], 'amount'],
    'three decimals' => [['amount' => '10.005'], 'amount'],
]);

test('at least one known weekday is needed', function (array $weekdays) {
    [$owner, $property, $plan] = packageSetup();

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [], 'weekdays' => $weekdays])
        ->assertSessionHasErrors('weekdays'.($weekdays === ['someday'] ? '.0' : ''));
})->with([
    'none' => [[]],
    'an unknown day' => [['someday']],
]);

test('a room type the plan does not sell is a validation error', function () {
    [$owner, $property, $plan] = packageSetup();
    $other = RoomType::factory()->for($property)->create();
    $foreign = RoomType::factory()->create();

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [packageRow($other)], 'weekdays' => ALL_WEEKDAYS])
        ->assertSessionHasErrors('prices.0.room_type_id');
    $this->put(packageUrl($property, $plan), ['prices' => [packageRow($foreign)], 'weekdays' => ALL_WEEKDAYS])
        ->assertSessionHasErrors('prices.0.room_type_id');

    expect($plan->packagePrices()->count())->toBe(0);
});

test('overlapping arrival periods of one room type are a validation error', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [
            packageRow($roomType, ['arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30']),
            packageRow($roomType, ['arrival_from' => '2026-06-30', 'arrival_to' => '2026-07-15']),
        ], 'weekdays' => ALL_WEEKDAYS])
        ->assertSessionHasErrors('prices.1.arrival_from');

    expect($plan->packagePrices()->count())->toBe(0);
});

test('touching periods and the same dates on another room type are fine', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();
    $other = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($other);

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [
            packageRow($roomType, ['arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30']),
            packageRow($roomType, ['arrival_from' => '2026-07-01', 'arrival_to' => '2026-07-31']),
            packageRow($other, ['arrival_from' => '2026-06-01', 'arrival_to' => '2026-06-30']),
        ], 'weekdays' => ALL_WEEKDAYS])
        ->assertSessionHasNoErrors();

    expect($plan->packagePrices()->count())->toBe(3);
});

test('an archived plan or a plan that is not a package is refused with an error toast and nothing is logged', function (string $kind) {
    [$owner, $property, $plan, $roomType] = packageSetup();

    $target = match ($kind) {
        'archived' => tap($plan)->update(['archived_at' => now()]),
        'not a package' => tap(RatePlan::factory()->for($property)->create(), fn (RatePlan $room) => $room->roomTypes()->attach($roomType)),
    };

    $this->actingAs($owner)
        ->put(packageUrl($property, $target), ['prices' => [packageRow($roomType)], 'weekdays' => ['friday']])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan or room types changed. Reload the page and try again.']);

    expect($target->packagePrices()->count())->toBe(0)
        ->and($target->arrivalWeekdays()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'rate_plan.package_pricing_updated')->count())->toBe(0);
})->with(['archived', 'not a package']);

test('a price id from another plan is refused and changes nothing', function () {
    [$owner, $property, $plan, $roomType] = packageSetup();
    $otherPlan = RatePlan::factory()->for($property)->package()->create();
    $otherPlan->roomTypes()->attach($roomType);
    $foreign = PackagePrice::factory()->for($otherPlan)->create(['room_type_id' => $roomType->id, 'amount' => '100.00']);

    $this->actingAs($owner)
        ->put(packageUrl($property, $plan), ['prices' => [packageRow($roomType, ['id' => $foreign->id, 'amount' => '999'])], 'weekdays' => ALL_WEEKDAYS])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan or room types changed. Reload the page and try again.']);

    expect($foreign->refresh()->amount)->toBe('100.00')->and($plan->packagePrices()->count())->toBe(0);
});

test('package pricing needs settings.manage and stays inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    $plan = RatePlan::factory()->for($frontDesk->property)->package()->create();

    $this->actingAs($frontDesk)->get(route('settings.rate-plans.pricing.edit', [$code, $plan]))->assertForbidden();
    $this->put(route('settings.rate-plans.pricing.package.update', [$code, $plan]), ['prices' => [], 'weekdays' => ['friday']])->assertForbidden();

    [$owner, $property] = packageSetup();
    $this->actingAs($owner)
        ->get(route('settings.rate-plans.pricing.edit', [$property->code, $plan]))
        ->assertNotFound();
});

test('the package details hold the nights, and a per-room package charges extra guests per stay', function () {
    [$owner, $property, $plan] = packageSetup(PackageUnit::PerRoom);

    $this->actingAs($owner)
        ->put(route('settings.rate-plans.update', [$property->code, $plan]), ['name' => $plan->name, 'fixed_nights' => 3, 'extra_adult_amount' => '80', 'extra_child_amount' => '40'])
        ->assertSessionHasNoErrors();

    $plan->refresh();

    expect($plan->fixed_nights)->toBe(3)
        ->and($plan->extra_adult_amount)->toBe('80.00')
        ->and($plan->extra_child_amount)->toBe('40.00');

    $this->put(route('settings.rate-plans.update', [$property->code, $plan]), ['name' => $plan->name, 'fixed_nights' => 31, 'extra_adult_amount' => '80', 'extra_child_amount' => '40'])
        ->assertSessionHasErrors('fixed_nights');
});

test('a per-guest package has a children tab and starts with child prices, a per-room package does not', function () {
    [$owner, $property, $perGuest] = packageSetup();
    app(SeedAgeGroups::class)($property);
    $perRoom = RatePlan::factory()->for($property)->package(PackageUnit::PerRoom)->create();

    $this->actingAs($owner)
        ->post(route('settings.rate-plans.store', $property->code), ['name' => 'Family Pack', 'pricing_basis' => 'package', 'fixed_nights' => 2, 'package_unit' => 'per_guest']);

    expect(RatePlan::query()->where('name', 'Family Pack')->sole()->childPrices()->count())->toBe(2);

    $this->get(route('settings.rate-plans.child-prices.edit', [$property->code, $perGuest]))->assertOk();
    $this->get(route('settings.rate-plans.child-prices.edit', [$property->code, $perRoom]))->assertNotFound();
});

test('a new age group adds a child price to per-guest packages only', function () {
    [$owner, $property, $perGuest] = packageSetup();
    app(SeedAgeGroups::class)($property);
    $perRoom = RatePlan::factory()->for($property)->package(PackageUnit::PerRoom)->create();
    [$infant, $child] = $property->ageGroups()->ordered()->get();

    app(SaveAgeGroups::class)($property, $owner, [
        ['id' => $infant->id, 'label' => 'Infant', 'max_age' => 3],
        ['id' => $child->id, 'label' => 'Child', 'max_age' => 11],
        ['id' => null, 'label' => 'Teen', 'max_age' => 15],
    ]);

    expect($perGuest->childPrices()->count())->toBe(1)
        ->and($perGuest->refresh()->needs_child_price_review)->toBeTrue()
        ->and($perRoom->childPrices()->count())->toBe(0);
});

test('the database needs a package to have its nights and unit, and only a package', function () {
    expect(fn () => RatePlan::factory()->create(['pricing_basis' => PricingBasis::Package]))->toThrow(QueryException::class);
    expect(fn () => RatePlan::factory()->package()->create(['fixed_nights' => 0]))->toThrow(QueryException::class);
    expect(fn () => RatePlan::factory()->package()->create(['package_unit' => null]))->toThrow(QueryException::class);
    expect(fn () => RatePlan::factory()->create(['fixed_nights' => 2]))->toThrow(QueryException::class);
});

test('the database refuses an arrival period that ends before it starts and a negative price', function () {
    $plan = RatePlan::factory()->package()->create();

    expect(fn () => PackagePrice::factory()->for($plan)->create(['arrival_from' => '2026-06-10', 'arrival_to' => '2026-06-09']))->toThrow(QueryException::class);
    expect(fn () => PackagePrice::factory()->for($plan)->create(['amount' => '-1']))->toThrow(QueryException::class);
});

test('overlap finds the first period that clashes with an earlier one of the same room type', function (array $rows, ?int $expected) {
    $prices = array_map(fn (array $row): array => ['room_type_id' => $row[0], 'arrival_from' => $row[1], 'arrival_to' => $row[2]], $rows);

    expect(app(PackagePriceOverlap::class)->firstOverlap($prices))->toBe($expected);
})->with([
    'no rows' => [[], null],
    'apart' => [[[1, '2026-06-01', '2026-06-10'], [1, '2026-06-11', '2026-06-20']], null],
    'sharing one day' => [[[1, '2026-06-01', '2026-06-10'], [1, '2026-06-10', '2026-06-20']], 1],
    'inside another' => [[[1, '2026-06-01', '2026-06-30'], [1, '2026-06-10', '2026-06-12']], 1],
    'around another' => [[[1, '2026-06-10', '2026-06-12'], [1, '2026-06-01', '2026-06-30']], 1],
    'same dates, another room type' => [[[1, '2026-06-01', '2026-06-10'], [2, '2026-06-01', '2026-06-10']], null],
    'the later row clashes, not the earlier' => [[[1, '2026-06-01', '2026-06-10'], [2, '2026-06-01', '2026-06-10'], [1, '2026-06-05', '2026-06-06']], 2],
]);
