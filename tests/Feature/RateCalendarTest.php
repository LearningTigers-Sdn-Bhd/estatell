<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

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
    $package = RatePlan::factory()->for($property)->create(['name' => 'Honeymoon', 'pricing_basis' => PricingBasis::Package]);
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
    $package = RatePlan::factory()->for($property)->create(['pricing_basis' => PricingBasis::Package]);
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

test('more plans and dates do not add queries', function () {
    [$owner, $property, $plan, $roomType] = calendarSetup();
    calendarRate($plan, $roomType, '2026-10-05');
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
        $extraRoomType = RoomType::factory()->for($property)->create();
        $extra->roomTypes()->attach([$roomType->id, $extraRoomType->id]);
        calendarRate($extra, $roomType, '2026-10-05');
        calendarRate($extra, $extraRoomType, '2026-10-06');
    }

    expect($countQueries())->toBe($before);
});
