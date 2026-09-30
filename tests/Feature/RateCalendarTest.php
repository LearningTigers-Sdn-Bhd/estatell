<?php

use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Enums\RateDerivation;
use App\Enums\Weekday;
use App\Enums\WeekendNights;
use App\Models\ChargeItem;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/** @return array{0: User, 1: Property, 2: RatePlan, 3: RoomType} */
function calendarSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Basic']);
    $roomType = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $plan->roomTypes()->attach($roomType);

    return [$owner, $property, $plan, $roomType];
}

function calendarRate(RatePlan $plan, RoomType $roomType, string $date, array $attributes = []): Rate
{
    return Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => '200.00', ...$attributes]);
}

test('the calendar shows the range of prices and restrictions for each room type and plan', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    calendarRate($plan, $roomType, '2026-10-05');
    calendarRate($plan, $roomType, '2026-10-06', ['amount' => '250.00', 'stop_sell' => true, 'min_stay' => 2]);
    calendarRate($plan, $roomType, '2026-11-01');

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rates/calendar')
            ->where('calendar.from', '2026-10-01')
            ->where('calendar.to', '2026-10-31')
            ->has('calendar.dates', 31)
            ->where('calendar.dates.0', '2026-10-01')
            ->has('calendar.roomTypes', 1)
            ->where('calendar.roomTypes.0.name', 'Deluxe')
            ->has('calendar.roomTypes.0.plans', 1)
            ->where('calendar.roomTypes.0.plans.0.derived', false)
            ->has('calendar.roomTypes.0.plans.0.cells', 2)
            ->where('calendar.roomTypes.0.plans.0.cells.0.date', '2026-10-05')
            ->where('calendar.roomTypes.0.plans.0.cells.0.amount', '200.00')
            ->where('calendar.roomTypes.0.plans.0.cells.1.stopSell', true)
            ->where('calendar.roomTypes.0.plans.0.cells.1.minStay', 2)
            ->where('canManage', true)
        );
});

test('the calendar opens on today and the next 13 days in the property time zone', function () {
    [$owner, $property] = calendarSetup();
    $this->travelTo(now()->setDate(2026, 12, 20));

    $this->actingAs($owner)
        ->get(route('rates.calendar', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.from', '2026-12-20')
            ->where('calendar.to', '2027-01-02')
            ->has('calendar.dates', 14)
            ->where('calendar.dates.13', '2027-01-02')
            ->where('today', '2026-12-20')
        );
});

test('the calendar sends the weekend days of the property setting', function () {
    [$owner, $property] = calendarSetup();
    $property->settings->update(['weekend_nights' => WeekendNights::SatSun]);

    $this->actingAs($owner)
        ->get(route('rates.calendar', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('weekendDays', [6, 7]));

    $property->settings->update(['weekend_nights' => WeekendNights::FriSat]);

    $this->get(route('rates.calendar', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('weekendDays', [5, 6]));
});

test('a start date alone shows 14 days from it', function () {
    [$owner, $property] = calendarSetup();

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-05']))
        ->assertInertia(fn (Assert $page) => $page->where('calendar.to', '2026-10-18')->has('calendar.dates', 14));
});

test('the longest range is 62 days', function () {
    [$owner, $property] = calendarSetup();

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-12-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('calendar.dates', 62));

    $this->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-12-02']))->assertSessionHasErrors('to');
});

test('a range that is not a range is refused', function (array $query, string $field) {
    [$owner, $property] = calendarSetup();

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, ...$query]))
        ->assertSessionHasErrors($field);
})->with([
    'a bad date' => [['from' => '2026-13-01'], 'from'],
    'an end before the start' => [['from' => '2026-10-10', 'to' => '2026-10-09'], 'to'],
    'an end with no start' => [['to' => '2026-10-10'], 'from'],
]);

test('a derived plan shows its parent price after the derivation and keeps its own restrictions', function () {
    [$owner, $property, $parent, $roomType] = calendarSetup();
    $derived = RatePlan::factory()->for($property)->create([
        'name' => 'Member',
        'parent_rate_plan_id' => $parent->id,
        'derivation' => RateDerivation::Percent,
        'derivation_value' => '-10',
    ]);
    $derived->roomTypes()->attach($roomType);
    calendarRate($parent, $roomType, '2026-10-05');
    calendarRate($parent, $roomType, '2026-10-06', ['amount' => '300.00']);
    calendarRate($derived, $roomType, '2026-10-06', ['amount' => null, 'closed_to_arrival' => true]);
    calendarRate($derived, $roomType, '2026-10-07', ['amount' => null, 'stop_sell' => true]);

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.roomTypes.0.plans.1.name', 'Member')
            ->where('calendar.roomTypes.0.plans.1.derived', true)
            ->where('calendar.roomTypes.0.plans.1.followsName', 'Basic')
            ->has('calendar.roomTypes.0.plans.1.cells', 3)
            ->where('calendar.roomTypes.0.plans.1.cells.0.amount', '180.00')
            ->where('calendar.roomTypes.0.plans.1.cells.1.amount', '270.00')
            ->where('calendar.roomTypes.0.plans.1.cells.1.closedToArrival', true)
            ->where('calendar.roomTypes.0.plans.1.cells.2.amount', null)
            ->where('calendar.roomTypes.0.plans.1.cells.2.stopSell', true)
        );
});

test('a package plan shows restrictions without a price', function () {
    [$owner, $property, , $roomType] = calendarSetup();
    $package = RatePlan::factory()->for($property)->package()->create(['name' => 'Honeymoon']);
    $package->roomTypes()->attach($roomType);
    calendarRate($package, $roomType, '2026-10-05', ['amount' => null, 'min_stay' => 3]);

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.roomTypes.0.plans.1.name', 'Honeymoon')
            ->where('calendar.roomTypes.0.plans.1.cells.0.amount', null)
            ->where('calendar.roomTypes.0.plans.1.cells.0.minStay', 3)
        );
});

test('archived plans, archived room types and unlinked room types are left out', function () {
    [$owner, $property, $plan] = calendarSetup();
    $archivedPlan = RatePlan::factory()->for($property)->archived()->create(['name' => 'Old']);
    $archivedRoomType = RoomType::factory()->for($property)->create(['archived_at' => now()]);
    $unlinked = RoomType::factory()->for($property)->create(['name' => 'Unlinked']);
    $archivedPlan->roomTypes()->attach($plan->roomTypes);
    $plan->roomTypes()->attach($archivedRoomType);

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('calendar.roomTypes', 1)
            ->where('calendar.roomTypes.0.name', 'Deluxe')
            ->has('calendar.roomTypes.0.plans', 1)
            ->where('calendar.roomTypes.0.plans.0.name', 'Basic')
        );

    expect($unlinked->exists)->toBeTrue();
});

test('rates of another property never show', function () {
    [$owner, $property] = calendarSetup();
    calendarRate(RatePlan::factory()->create(), RoomType::factory()->create(), '2026-10-05');

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-10-31']))
        ->assertInertia(fn (Assert $page) => $page->has('calendar.roomTypes', 1)->has('calendar.roomTypes.0.plans.0.cells', 0));
});

test('plans whose prices run out are listed, and priced, derived and package plans are not', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $this->travelTo(now()->setDate(2026, 10, 15));
    $priced = RatePlan::factory()->for($property)->create(['name' => 'Priced']);
    $priced->roomTypes()->attach($roomType);
    calendarRate($priced, $roomType, '2027-03-01');
    $derived = RatePlan::factory()->for($property)->create(['parent_rate_plan_id' => $priced->id, 'derivation' => RateDerivation::Amount, 'derivation_value' => '10']);
    $derived->roomTypes()->attach($roomType);
    $package = RatePlan::factory()->for($property)->package()->create();
    $package->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->get(route('rates.calendar', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('calendar.ending', 1)
            ->where('calendar.ending.0.ratePlanId', $plan->id)
            ->where('calendar.ending.0.roomTypeNames', ['Deluxe'])
        );
});

test('front desk can view the calendar but not manage it', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $frontDesk->property->code;
    PropertySetting::factory()->for($frontDesk->property)->create();
    $plan = RatePlan::factory()->for($frontDesk->property)->create();

    $this->actingAs($frontDesk)
        ->get(route('rates.calendar', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false));

    $this->post(route('rates.calendar.extend', [$code, $plan]))->assertForbidden();
});

test('a role without rates.view cannot open the calendar', function () {
    $user = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    PropertySetting::factory()->for($user->property)->create();
    Role::query()->where('property_id', $user->property_id)->where('default_role', DefaultRoleEnum::FrontDesk)->firstOrFail()
        ->revokePermissionTo(PermissionEnum::RatesView->value);

    $this->actingAs($user)->get(route('rates.calendar', $user->property->code))->assertForbidden();
});

test('the calendar is not reachable through another property code', function () {
    [$owner] = calendarSetup();
    $other = Property::factory()->create();

    $this->actingAs($owner)->get(route('rates.calendar', $other->code))->assertNotFound();
});

test('default roles get the rate permissions', function () {
    expect(DefaultRoleEnum::Owner->permissions())->toContain(PermissionEnum::RatesView, PermissionEnum::RatesManage)
        ->and(DefaultRoleEnum::Manager->permissions())->toContain(PermissionEnum::RatesView, PermissionEnum::RatesManage)
        ->and(DefaultRoleEnum::FrontDesk->permissions())->toContain(PermissionEnum::RatesView)->not->toContain(PermissionEnum::RatesManage)
        ->and(DefaultRoleEnum::Housekeeping->permissions())->toContain(PermissionEnum::RatesView)
        ->and(DefaultRoleEnum::Finance->permissions())->toContain(PermissionEnum::RatesView);
});

test('extending from the calendar copies prices forward and returns to the page', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $this->travelTo(now()->setDate(2026, 10, 15));
    foreach (range(1, 7) as $day) {
        calendarRate($plan, $roomType, '2026-10-0'.$day);
    }

    $this->actingAs($owner)
        ->from(route('rates.calendar', $property->code))
        ->post(route('rates.calendar.extend', [$property->code, $plan]))
        ->assertRedirect(route('rates.calendar', $property->code))
        ->assertInertiaFlash('toast.type', 'success');

    expect(Rate::query()->where('rate_plan_id', $plan->id)->count())->toBeGreaterThan(300);
});

test('extending a plan with no prices says so', function () {
    [$owner, $property, $plan] = calendarSetup();

    $this->actingAs($owner)
        ->post(route('rates.calendar.extend', [$property->code, $plan]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'There are no prices to copy yet. Set prices first.']);
});

test('extending is limited to plans of the property', function () {
    [$owner, $property] = calendarSetup();
    $theirs = RatePlan::factory()->create();

    $this->actingAs($owner)->post(route('rates.calendar.extend', [$property->code, $theirs]))->assertNotFound();
});

test('more plans and dates do not add queries (availability costs a fixed number of queries for each room type)', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    calendarRate($plan, $roomType, '2026-10-05');
    $otherRoomType = RoomType::factory()->for($property)->create();
    $plan->roomTypes()->attach($otherRoomType);
    $countQueries = function () use ($owner, $property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $countQueries();
    $before = $countQueries();

    foreach (range(1, 4) as $number) {
        $extra = RatePlan::factory()->for($property)->create();
        $extra->roomTypes()->attach([$roomType->id, $otherRoomType->id]);
        calendarRate($extra, $roomType, '2026-10-05');
        calendarRate($extra, $otherRoomType, '2026-10-06');
    }

    expect($countQueries())->toBe($before);
});

/**
 * The request the calendar's date editor sends: one date, one room type, and the restrictions to set.
 *
 * @param  array<string, mixed>|null  $day  The price for the date's weekday, or null to leave the price alone.
 * @param  array<string, mixed>  $restrictions
 * @return array<string, mixed>
 */
function calendarSave(RoomType $roomType, string $date, ?array $day, array $restrictions = []): array
{
    return [
        'from' => $date,
        'to' => $date,
        'room_types' => [[
            'room_type_id' => $roomType->id,
            'days' => $day === null ? [] : [['weekday' => (int) Date::parse($date)->dayOfWeekIso, ...$day]],
        ]],
        'restrictions' => $restrictions,
    ];
}

test('the calendar sends the explicit prices by adult count of each cell', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $rate = calendarRate($plan, $roomType, '2026-10-05');
    $rate->occupancyPrices()->create(['adults' => 2, 'amount' => '260.00']);
    $rate->occupancyPrices()->create(['adults' => 1, 'amount' => '200.00']);

    $this->actingAs($owner)
        ->get(route('rates.calendar', [$property->code, 'from' => '2026-10-05', 'to' => '2026-10-06']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('calendar.roomTypes.0.maxAdults', $roomType->max_adults)
            ->where('calendar.roomTypes.0.baseOccupancy', $roomType->base_occupancy)
            ->where('calendar.roomTypes.0.plans.0.cells.0.occupancy', [
                ['adults' => 1, 'amount' => '200.00'],
                ['adults' => 2, 'amount' => '260.00'],
            ])
        );
});

test('saving one date changes only that date and replaces its prices by adult count', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $roomType->update(['max_adults' => 3]);
    $first = calendarRate($plan, $roomType, '2026-10-05');
    $second = calendarRate($plan, $roomType, '2026-10-06');
    $first->occupancyPrices()->create(['adults' => 1, 'amount' => '150.00']);

    $this->actingAs($owner)
        ->from(route('rates.calendar', $property->code))
        ->put(route('rates.calendar.update', [$property->code, $plan]), calendarSave($roomType, '2026-10-05', [
            'amount' => '210',
            'occupancy' => [['adults' => 2, 'amount' => '260'], ['adults' => 3, 'amount' => '300']],
        ], ['stop_sell' => false, 'min_stay' => 2]))
        ->assertRedirect(route('rates.calendar', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates saved for 1 date.']);

    $first->refresh();

    expect($first->amount)->toBe('210.00')
        ->and($first->min_stay)->toBe(2)
        ->and($first->occupancyPrices()->orderBy('adults')->pluck('amount', 'adults')->all())->toBe([2 => '260.00', 3 => '300.00'])
        ->and($second->refresh()->amount)->toBe('200.00')
        ->and($second->min_stay)->toBeNull();
});

test('restrictions can be set and cleared without touching the price', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $rate = calendarRate($plan, $roomType, '2026-10-05', ['stop_sell' => true, 'closed_to_arrival' => true, 'min_stay' => 3, 'max_stay' => 5]);

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $plan]), calendarSave($roomType, '2026-10-05', null, [
            'stop_sell' => false, 'closed_to_arrival' => false, 'closed_to_departure' => true, 'min_stay' => null, 'max_stay' => null,
        ]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates saved for 1 date.']);

    expect($rate->refresh())
        ->amount->toBe('200.00')
        ->stop_sell->toBeFalse()
        ->closed_to_arrival->toBeFalse()
        ->closed_to_departure->toBeTrue()
        ->min_stay->toBeNull()
        ->max_stay->toBeNull();
});

test('a date with no rate row gets one', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $plan]), calendarSave($roomType, '2026-10-05', ['amount' => '180', 'occupancy' => []], ['stop_sell' => true]))
        ->assertSessionHasNoErrors();

    expect(Rate::query()->where('rate_plan_id', $plan->id)->sole())
        ->amount->toBe('180.00')
        ->stop_sell->toBeTrue();
});

test('a derived plan takes restrictions from the calendar but never a price', function () {
    [$owner, $property, $parent, $roomType] = calendarSetup();
    $derived = RatePlan::factory()->for($property)->create([
        'parent_rate_plan_id' => $parent->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10',
    ]);
    $derived->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $derived]), calendarSave($roomType, '2026-10-05', null, ['closed_to_departure' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates saved for 1 date.']);

    expect(Rate::query()->where('rate_plan_id', $derived->id)->sole())
        ->amount->toBeNull()
        ->closed_to_departure->toBeTrue();

    $this->put(route('rates.calendar.update', [$property->code, $derived]), calendarSave($roomType, '2026-10-06', ['amount' => '90', 'occupancy' => []], ['stop_sell' => true]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan or room types changed. Reload the page and try again.']);

    expect(Rate::query()->where('rate_plan_id', $derived->id)->count())->toBe(1);
});

test('a package plan takes restrictions from the calendar but never a price', function () {
    [$owner, $property, , $roomType] = calendarSetup();
    $package = RatePlan::factory()->for($property)->package()->create();
    $package->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $package]), calendarSave($roomType, '2026-10-05', null, ['min_stay' => 3]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates saved for 1 date.']);

    $this->put(route('rates.calendar.update', [$property->code, $package]), calendarSave($roomType, '2026-10-06', ['amount' => '90', 'occupancy' => []], ['min_stay' => 3]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan or room types changed. Reload the page and try again.']);

    expect(Rate::query()->where('rate_plan_id', $package->id)->sole()->min_stay)->toBe(3);
});

test('a bad edit from the calendar is refused with its field errors', function (array $day, array $restrictions, string $field) {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $roomType->update(['max_adults' => 2]);

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $plan]), calendarSave($roomType, '2026-10-05', $day, $restrictions))
        ->assertSessionHasErrors($field);

    expect(Rate::query()->count())->toBe(0);
})->with([
    'a max stay below the min stay' => [['amount' => '100', 'occupancy' => []], ['min_stay' => 5, 'max_stay' => 2], 'restrictions.max_stay'],
    'a negative price' => [['amount' => '-5', 'occupancy' => []], [], 'room_types.0.days.0.amount'],
    'more adults than the room sleeps' => [['amount' => '100', 'occupancy' => [['adults' => 3, 'amount' => '200']]], [], 'room_types.0.days.0.occupancy.0.adults'],
    'a min stay of zero' => [['amount' => '100', 'occupancy' => []], ['min_stay' => 0], 'restrictions.min_stay'],
]);

test('saving from the calendar is logged', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $plan]), calendarSave($roomType, '2026-10-05', ['amount' => '180', 'occupancy' => []]));

    $activity = Activity::query()->where('event', 'rates.updated')->sole();

    expect($activity->causer_id)->toBe($owner->id)
        ->and($activity->properties['dates'])->toBe(1)
        ->and($activity->properties['from'])->toBe('2026-10-05');
});

test('saving from the calendar needs rates.manage and stays inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    PropertySetting::factory()->for($frontDesk->property)->create();
    $plan = RatePlan::factory()->for($frontDesk->property)->create();
    $roomType = RoomType::factory()->for($frontDesk->property)->create();
    $plan->roomTypes()->attach($roomType);

    $this->actingAs($frontDesk)
        ->put(route('rates.calendar.update', [$frontDesk->property->code, $plan]), calendarSave($roomType, '2026-10-05', ['amount' => '100', 'occupancy' => []]))
        ->assertForbidden();

    [$owner, $property] = calendarSetup();
    $theirs = RatePlan::factory()->create();

    $this->actingAs($owner)
        ->put(route('rates.calendar.update', [$property->code, $theirs]), calendarSave($roomType, '2026-10-05', ['amount' => '100', 'occupancy' => []]))
        ->assertNotFound();

    expect(Rate::query()->count())->toBe(0);
});

/**
 * The request the bulk edit page sends: a date range, one room type and the same price for every weekday.
 *
 * @param  array<string, mixed>  $restrictions
 * @return array<string, mixed>
 */
function bulkSave(RoomType $roomType, string $from, string $to, ?string $amount, array $restrictions = []): array
{
    return [
        'from' => $from,
        'to' => $to,
        'room_types' => [[
            'room_type_id' => $roomType->id,
            'days' => $amount === null ? [] : array_map(fn (int $weekday): array => ['weekday' => $weekday, 'amount' => $amount, 'occupancy' => []], range(1, 7)),
        ]],
        'restrictions' => $restrictions,
    ];
}

test('the bulk edit page lists the plans and picks the first by default', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $second = RatePlan::factory()->for($property)->create(['name' => 'Breakfast']);
    $second->roomTypes()->attach($roomType);
    RatePlan::factory()->for($property)->archived()->create(['name' => 'Old']);

    $this->actingAs($owner)
        ->get(route('rates.calendar.bulk', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rates/bulk')
            ->has('plans', 2)
            ->where('plans.0.name', 'Basic')
            ->where('plans.0.hasOwnPrices', true)
            ->where('ratePlan.id', $plan->id)
            ->has('roomTypes', 1)
            ->where('roomTypes.0.id', $roomType->id)
            ->where('from', '')
            ->where('to', '')
            ->has('weekendNightsOptions', 2)
        );

    $this->get(route('rates.calendar.bulk', [$property->code, 'plan' => $second->id, 'from' => '2026-10-05', 'to' => '2026-10-18']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ratePlan.id', $second->id)
            ->where('from', '2026-10-05')
            ->where('to', '2026-10-18')
        );
});

test('the bulk edit page ignores a plan of another property and one that is archived', function () {
    [$owner, $property, $plan] = calendarSetup();
    $theirs = RatePlan::factory()->create();
    $archived = RatePlan::factory()->for($property)->archived()->create();

    $this->actingAs($owner)
        ->get(route('rates.calendar.bulk', [$property->code, 'plan' => $theirs->id]))
        ->assertInertia(fn (Assert $page) => $page->where('ratePlan.id', $plan->id));

    $this->get(route('rates.calendar.bulk', [$property->code, 'plan' => $archived->id]))
        ->assertInertia(fn (Assert $page) => $page->where('ratePlan.id', $plan->id));
});

test('the bulk edit page says which plans have prices of their own', function () {
    [$owner, $property, $parent, $roomType] = calendarSetup();
    $derived = RatePlan::factory()->for($property)->create(['name' => 'Member', 'parent_rate_plan_id' => $parent->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10']);
    $package = RatePlan::factory()->for($property)->package()->create(['name' => 'Honeymoon']);
    $derived->roomTypes()->attach($roomType);
    $package->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->get(route('rates.calendar.bulk', [$property->code, 'plan' => $derived->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plans.1.name', 'Member')
            ->where('plans.1.derived', true)
            ->where('plans.1.followsName', 'Basic')
            ->where('plans.1.hasOwnPrices', false)
            ->where('plans.2.hasOwnPrices', false)
            ->where('ratePlan.id', $derived->id)
        );
});

test('the bulk edit page starts empty when there are no plans', function () {
    $owner = User::factory()->owner()->create();
    PropertySetting::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->get(route('rates.calendar.bulk', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->has('plans', 0)->where('ratePlan', null)->has('roomTypes', 0));
});

test('a bulk edit saves prices and restrictions over the whole range, then shows the range in the calendar', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();

    $this->actingAs($owner)
        ->put(route('rates.calendar.bulk.update', [$property->code, $plan]), bulkSave($roomType, '2026-10-05', '2026-10-11', '210', ['closed_to_arrival' => true, 'min_stay' => 2]))
        ->assertRedirect(route('rates.calendar', [$property->code, 'from' => '2026-10-05', 'to' => '2026-10-11']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates saved for 7 dates.']);

    $rates = Rate::query()->where('rate_plan_id', $plan->id)->orderBy('date')->get();

    expect($rates)->toHaveCount(7)
        ->and($rates->pluck('amount')->unique()->all())->toBe(['210.00'])
        ->and($rates->every(fn (Rate $rate): bool => $rate->closed_to_arrival && $rate->min_stay === 2 && ! $rate->stop_sell))->toBeTrue();
});

test('a bulk edit with only restrictions keeps the saved prices and other restrictions', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $kept = calendarRate($plan, $roomType, '2026-10-06', ['amount' => '333.00', 'min_stay' => 4, 'closed_to_departure' => true]);

    $this->actingAs($owner)
        ->put(route('rates.calendar.bulk.update', [$property->code, $plan]), bulkSave($roomType, '2026-10-05', '2026-10-07', null, ['stop_sell' => true]))
        ->assertRedirect();

    expect(Rate::query()->where('rate_plan_id', $plan->id)->count())->toBe(3)
        ->and(Rate::query()->where('rate_plan_id', $plan->id)->where('stop_sell', true)->count())->toBe(3)
        ->and($kept->refresh())
        ->amount->toBe('333.00')
        ->min_stay->toBe(4)
        ->closed_to_departure->toBeTrue();
});

test('a bulk edit longer than the calendar shows lets the calendar open on its first 62 days', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();

    $this->actingAs($owner)
        ->put(route('rates.calendar.bulk.update', [$property->code, $plan]), bulkSave($roomType, '2026-10-01', '2027-03-31', null, ['stop_sell' => true]))
        ->assertRedirect(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-12-01']));

    $this->get(route('rates.calendar', [$property->code, 'from' => '2026-10-01', 'to' => '2026-12-01']))->assertOk();
});

test('a bulk edit gives a derived plan restrictions but never a price', function () {
    [$owner, $property, $parent, $roomType] = calendarSetup();
    $derived = RatePlan::factory()->for($property)->create(['parent_rate_plan_id' => $parent->id, 'derivation' => RateDerivation::Percent, 'derivation_value' => '-10']);
    $derived->roomTypes()->attach($roomType);

    $this->actingAs($owner)
        ->put(route('rates.calendar.bulk.update', [$property->code, $derived]), bulkSave($roomType, '2026-10-05', '2026-10-06', null, ['closed_to_arrival' => true]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Rates saved for 2 dates.']);

    expect(Rate::query()->where('rate_plan_id', $derived->id)->whereNull('amount')->where('closed_to_arrival', true)->count())->toBe(2);

    $this->put(route('rates.calendar.bulk.update', [$property->code, $derived]), bulkSave($roomType, '2026-10-07', '2026-10-08', '90', ['closed_to_arrival' => true]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The rate plan or room types changed. Reload the page and try again.']);

    expect(Rate::query()->where('rate_plan_id', $derived->id)->count())->toBe(2);
});

test('a bad bulk edit is refused with its field errors', function (array $overrides, string $field) {
    [$owner, $property, $plan, $roomType] = calendarSetup();

    $this->actingAs($owner)
        ->put(route('rates.calendar.bulk.update', [$property->code, $plan]), [...bulkSave($roomType, '2026-10-05', '2026-10-11', '100'), ...$overrides])
        ->assertSessionHasErrors($field);

    expect(Rate::query()->count())->toBe(0);
})->with([
    'an end before the start' => [['to' => '2026-10-01'], 'to'],
    'a range over two years' => [['to' => '2028-10-20'], 'to'],
    'no dates' => [['from' => null, 'to' => null], 'from'],
    'a max stay below the min stay' => [['restrictions' => ['min_stay' => 5, 'max_stay' => 2]], 'restrictions.max_stay'],
]);

test('bulk edit needs rates.manage and stays inside the property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    PropertySetting::factory()->for($frontDesk->property)->create();
    $plan = RatePlan::factory()->for($frontDesk->property)->create();
    $roomType = RoomType::factory()->for($frontDesk->property)->create();
    $plan->roomTypes()->attach($roomType);

    $this->actingAs($frontDesk)->get(route('rates.calendar.bulk', $frontDesk->property->code))->assertForbidden();
    $this->put(route('rates.calendar.bulk.update', [$frontDesk->property->code, $plan]), bulkSave($roomType, '2026-10-05', '2026-10-06', '100'))->assertForbidden();

    [$owner, $property] = calendarSetup();
    $theirs = RatePlan::factory()->create();

    $this->actingAs($owner)
        ->put(route('rates.calendar.bulk.update', [$property->code, $theirs]), bulkSave($roomType, '2026-10-05', '2026-10-06', '100'))
        ->assertNotFound();

    expect(Rate::query()->count())->toBe(0);
});

test('the rate calendar and its bulk edit page both sit in the front desk sidebar', function (string $routeName) {
    [$owner, $property] = calendarSetup();

    $this->actingAs($owner)
        ->get(route($routeName, $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'front-desk')
            ->where('navigation.contexts.0.groups.2.label', 'Operations')
            ->where('navigation.contexts.0.groups.2.items.0.title', 'Rate calendar')
            ->where('navigation.contexts.0.groups.2.items.0.active', true)
        );
})->with(['rates.calendar', 'rates.calendar.bulk']);

/** @return array{0: RatePlan, 1: RoomType} A package plan linked to a new room type. */
function calendarPackage(Property $property, array $attributes = [], string $roomTypeName = 'Suite'): array
{
    $package = RatePlan::factory()->for($property)->package()->create(['name' => 'Honeymoon', ...$attributes]);
    $roomType = RoomType::factory()->for($property)->create(['name' => $roomTypeName]);
    $package->roomTypes()->attach($roomType);

    return [$package, $roomType];
}

function calendarPage($test, User $owner, Property $property, array $range = ['from' => '2026-10-01', 'to' => '2026-10-31'])
{
    return $test->actingAs($owner)->get(route('rates.calendar', [$property->code, ...$range]));
}

test('a package plan shows its stay price on every arrival date of a price period, merged with the restrictions', function () {
    [$owner, $property] = calendarSetup();
    [$package, $suite] = calendarPackage($property);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-10-03', 'arrival_to' => '2026-10-07', 'amount' => '600.00']);
    calendarRate($package, $suite, '2026-10-05', ['amount' => null, 'min_stay' => 2, 'closed_to_arrival' => true]);
    calendarRate($package, $suite, '2026-10-20', ['amount' => null, 'stop_sell' => true]);

    calendarPage($this, $owner, $property)->assertInertia(fn (Assert $page) => $page
        ->where('calendar.roomTypes.1.name', 'Suite')
        ->where('calendar.roomTypes.1.plans.0.pricingBasis', 'package')
        ->where('calendar.roomTypes.1.plans.0.fixedNights', 2)
        ->where('calendar.roomTypes.1.plans.0.packageUnit', 'per_guest')
        ->has('calendar.roomTypes.1.plans.0.cells', 6)
        ->where('calendar.roomTypes.1.plans.0.cells.0.date', '2026-10-03')
        ->where('calendar.roomTypes.1.plans.0.cells.0.amount', '600.00')
        ->where('calendar.roomTypes.1.plans.0.cells.2.date', '2026-10-05')
        ->where('calendar.roomTypes.1.plans.0.cells.2.amount', '600.00')
        ->where('calendar.roomTypes.1.plans.0.cells.2.minStay', 2)
        ->where('calendar.roomTypes.1.plans.0.cells.2.closedToArrival', true)
        ->where('calendar.roomTypes.1.plans.0.cells.4.date', '2026-10-07')
        ->where('calendar.roomTypes.1.plans.0.cells.5.date', '2026-10-20')
        ->where('calendar.roomTypes.1.plans.0.cells.5.amount', null)
        ->where('calendar.roomTypes.1.plans.0.cells.5.stopSell', true));
});

test('a price period is clipped to the range, and a period outside it shows nothing', function () {
    [$owner, $property] = calendarSetup();
    [$package, $suite] = calendarPackage($property);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-09-28', 'arrival_to' => '2026-10-02', 'amount' => '500.00']);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-10-30', 'arrival_to' => '2026-11-15', 'amount' => '700.00']);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-12-01', 'arrival_to' => '2026-12-31', 'amount' => '900.00']);

    calendarPage($this, $owner, $property)->assertInertia(fn (Assert $page) => $page
        ->has('calendar.roomTypes.1.plans.0.cells', 4)
        ->where('calendar.roomTypes.1.plans.0.cells.0.date', '2026-10-01')
        ->where('calendar.roomTypes.1.plans.0.cells.1.date', '2026-10-02')
        ->where('calendar.roomTypes.1.plans.0.cells.1.amount', '500.00')
        ->where('calendar.roomTypes.1.plans.0.cells.2.date', '2026-10-30')
        ->where('calendar.roomTypes.1.plans.0.cells.3.date', '2026-10-31')
        ->where('calendar.roomTypes.1.plans.0.cells.3.amount', '700.00'));
});

test('a package date on a weekday the package cannot start on is marked, and a package with no weekdays is not', function () {
    [$owner, $property] = calendarSetup();
    [$package, $suite] = calendarPackage($property);
    [$anyDay, $otherSuite] = calendarPackage($property, ['name' => 'Any day'], 'Villa');
    foreach ([[$package, $suite], [$anyDay, $otherSuite]] as [$plan, $roomType]) {
        PackagePrice::factory()->for($plan)->create(['room_type_id' => $roomType->id, 'arrival_from' => '2026-10-02', 'arrival_to' => '2026-10-05', 'amount' => '600.00']);
    }
    $package->arrivalWeekdays()->create(['weekday' => Weekday::Friday]);
    $package->arrivalWeekdays()->create(['weekday' => Weekday::Saturday]);

    calendarPage($this, $owner, $property)->assertInertia(fn (Assert $page) => $page
        ->where('calendar.roomTypes.1.plans.0.cells.0.date', '2026-10-02')
        ->where('calendar.roomTypes.1.plans.0.cells.0.arrivalNotAllowed', false)
        ->where('calendar.roomTypes.1.plans.0.cells.1.arrivalNotAllowed', false)
        ->where('calendar.roomTypes.1.plans.0.cells.2.arrivalNotAllowed', true)
        ->where('calendar.roomTypes.1.plans.0.cells.3.arrivalNotAllowed', true)
        ->where('calendar.roomTypes.2.plans.0.cells.0.arrivalNotAllowed', false)
        ->where('calendar.roomTypes.2.plans.0.cells.3.arrivalNotAllowed', false));
});

test('package prices are kept apart by room type and by plan', function () {
    [$owner, $property] = calendarSetup();
    [$package, $suite] = calendarPackage($property);
    $other = RoomType::factory()->for($property)->create(['name' => 'Villa']);
    $package->roomTypes()->attach($other);
    $otherPlan = RatePlan::factory()->for($property)->package()->create(['name' => 'Other']);
    $otherPlan->roomTypes()->attach($suite);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-10-02', 'arrival_to' => '2026-10-02', 'amount' => '600.00']);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $other->id, 'arrival_from' => '2026-10-03', 'arrival_to' => '2026-10-03', 'amount' => '800.00']);
    PackagePrice::factory()->for($otherPlan)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-10-04', 'arrival_to' => '2026-10-04', 'amount' => '999.00']);

    calendarPage($this, $owner, $property)->assertInertia(fn (Assert $page) => $page
        ->where('calendar.roomTypes.1.name', 'Suite')
        ->where('calendar.roomTypes.1.plans.0.name', 'Honeymoon')
        ->has('calendar.roomTypes.1.plans.0.cells', 1)
        ->where('calendar.roomTypes.1.plans.0.cells.0.amount', '600.00')
        ->where('calendar.roomTypes.1.plans.1.name', 'Other')
        ->has('calendar.roomTypes.1.plans.1.cells', 1)
        ->where('calendar.roomTypes.1.plans.1.cells.0.amount', '999.00')
        ->where('calendar.roomTypes.2.name', 'Villa')
        ->has('calendar.roomTypes.2.plans.0.cells', 1)
        ->where('calendar.roomTypes.2.plans.0.cells.0.amount', '800.00'));
});

test('a plan lists what it includes with the quantity and charging unit, in order', function () {
    [$owner, $property, $plan] = calendarSetup();
    $breakfast = ChargeItem::factory()->for($property)->create(['name' => 'Breakfast']);
    $boat = ChargeItem::factory()->for($property)->create(['name' => 'Boat transfer']);
    RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $boat->id, 'position' => 1, 'quantity' => 1, 'charging_unit' => ChargingUnit::PerStay]);
    RatePlanInclusion::factory()->for($plan)->create(['charge_item_id' => $breakfast->id, 'position' => 0, 'quantity' => 2, 'charging_unit' => ChargingUnit::PerGuestNight]);
    [$package] = calendarPackage($property);

    calendarPage($this, $owner, $property)->assertInertia(fn (Assert $page) => $page
        ->has('calendar.roomTypes.0.plans.0.inclusions', 2)
        ->where('calendar.roomTypes.0.plans.0.inclusions.0', ['name' => 'Breakfast', 'quantity' => 2, 'chargingUnit' => 'Per guest-night'])
        ->where('calendar.roomTypes.0.plans.0.inclusions.1', ['name' => 'Boat transfer', 'quantity' => 1, 'chargingUnit' => 'Per stay'])
        ->where('calendar.roomTypes.0.plans.0.fixedNights', null)
        ->where('calendar.roomTypes.0.plans.0.packageUnit', null)
        ->where('calendar.roomTypes.1.plans.0.inclusions', []));
});

test('package prices and inclusions add no query per plan', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    $queries = function () use ($owner, $property): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        calendarPage($this, $owner, $property);

        return count(DB::getQueryLog());
    };
    [$package, $suite] = calendarPackage($property);
    PackagePrice::factory()->for($package)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-10-02', 'arrival_to' => '2026-10-09']);
    RatePlanInclusion::factory()->for($package)->create();
    $queries();
    $before = $queries();

    foreach (range(1, 4) as $number) {
        $next = RatePlan::factory()->for($property)->package()->create(['name' => "Package {$number}"]);
        $next->roomTypes()->attach($suite);
        PackagePrice::factory()->for($next)->create(['room_type_id' => $suite->id, 'arrival_from' => '2026-10-02', 'arrival_to' => '2026-10-20']);
        RatePlanInclusion::factory()->for($next)->create();
        RatePlanInclusion::factory()->for($next)->create();
        $next->arrivalWeekdays()->create(['weekday' => Weekday::Friday]);
    }

    expect($queries())->toBe($before);
});

test('each room type lists its units, sold and open for every date of the range', function () {
    [$owner, $property, , $roomType] = calendarSetup();
    Room::factory()->count(4)->for($property)->create(['room_type_id' => $roomType->id]);
    $blocked = Room::factory()->for($property)->create(['room_type_id' => $roomType->id]);
    RoomBlock::factory()->create(['property_id' => $property->id, 'room_id' => $blocked->id, 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-03']);

    calendarPage($this, $owner, $property, ['from' => '2026-10-01', 'to' => '2026-10-04'])->assertInertia(fn (Assert $page) => $page
        ->has('calendar.roomTypes.0.availability', 4)
        ->where('calendar.roomTypes.0.availability.0', ['date' => '2026-10-01', 'units' => 5, 'sold' => 0, 'open' => 5])
        ->where('calendar.roomTypes.0.availability.1', ['date' => '2026-10-02', 'units' => 4, 'sold' => 0, 'open' => 4])
        ->where('calendar.roomTypes.0.availability.3', ['date' => '2026-10-04', 'units' => 5, 'sold' => 0, 'open' => 5]));
});

test('only staff who manage settings get the link to manage inclusions', function () {
    [$owner, $property] = calendarSetup();

    calendarPage($this, $owner, $property)->assertInertia(fn (Assert $page) => $page->where('canManageInclusions', true));
});
