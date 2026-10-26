<?php

require_once __DIR__.'/../Support/BookingSetup.php';

use App\Actions\Reservations\AssignRoom;
use App\Enums\DefaultRoleEnum;
use App\Models\ChargeItem;
use App\Models\ChargeItemChildPrice;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quoteBody(array $setup, array $overrides = []): array
{
    return ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'adults' => 2, ...$overrides];
}

function previewUrl(array $setup, string $route, array $query = []): string
{
    return route($route, ['property' => $setup['property']->code, ...$query]);
}

test('the preview routes need reservations.manage', function (string $method, string $route) {
    $setup = bookingSetup();
    $viewer = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($viewer)->{$method}(previewUrl($setup, $route), quoteBody($setup))->assertForbidden();
})->with([['postJson', 'reservations.quote'], ['getJson', 'reservations.free-units']]);

test('a quote carries the priced nights, the totals and the free units', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup))
        ->assertOk()
        ->assertJsonPath('errors', [])
        ->assertJsonPath('unitTotal', '200.00')
        ->assertJsonPath('lineTotal', '200.00')
        ->assertJsonPath('violations', [])
        ->assertJsonPath('available', 2)
        ->assertJsonCount(2, 'nights')
        ->assertJsonPath('nights.0', ['date' => '2026-10-20', 'base' => '100.00', 'extra' => '0.00', 'supplement' => '0.00', 'discount' => '0.00', 'amount' => '100.00']);
});

test('a bed line is priced for one bed and the line total is that times the beds', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, ['room_type_id' => $setup['dorm']->id, 'rate_plan_id' => $setup['bedPlan']->id, 'adults' => 1, 'beds' => 3]))
        ->assertOk()
        ->assertJsonPath('unitTotal', '80.00')
        ->assertJsonPath('lineTotal', '240.00')
        ->assertJsonPath('beds', 3)
        ->assertJsonPath('available', 3);
});

test('a quote lists the restriction warnings and shrinks the free units', function () {
    $setup = bookingSetup();
    Rate::query()->where('date', '2026-10-21')->where('rate_plan_id', $setup['plan']->id)->update(['stop_sell' => true]);
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    ReservationUnit::factory()->for($reservation)->withNights()->create(['room_type_id' => $setup['roomType']->id, 'arrival_date' => '2026-10-21', 'departure_date' => '2026-10-22']);

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup))
        ->assertOk()
        ->assertJsonPath('violations.0', 'Not for sale on 21 Oct 2026.')
        ->assertJsonPath('available', 1);
});

test('a line that cannot be priced answers with the refusal keyed by field', function (string $case) {
    $setup = bookingSetup();
    [$overrides, $field, $message] = match ($case) {
        'a plan not sold for the room type' => [['rate_plan_id' => $setup['bedPlan']->id], 'rate_plan_id', 'This rate plan is not sold for the room type.'],
        'a room type of another property' => [['room_type_id' => RoomType::factory()->create()->id], 'room_type_id', 'This room type is not for sale.'],
        'a plan of another property' => [['rate_plan_id' => RatePlan::factory()->create()->id], 'room_type_id', 'This room type is not for sale.'],
        'a departure before the arrival' => [['departure_date' => '2026-10-20'], 'departure_date', 'Departure must be after arrival.'],
        'nights without a rate' => [['arrival_date' => '2026-10-30', 'departure_date' => '2026-10-31'], 'form', 'No rate is set for 30 Oct 2026.'],
    };

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, $overrides))
        ->assertOk()
        ->assertJsonPath('errors', [$field => $message])
        ->assertJsonPath('unitTotal', null)
        ->assertJsonPath('nights', []);
})->with(['a plan not sold for the room type', 'a room type of another property', 'a plan of another property', 'a departure before the arrival', 'nights without a rate']);

test('a quote checks the shape of the line', function (array $overrides, string $field) {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no adults' => [['adults' => 0], 'adults'],
    'a bad date' => [['arrival_date' => 'soon'], 'arrival_date'],
    'a child age over 17' => [['child_ages' => [18]], 'child_ages.0'],
]);

test('free units lists the rooms free on every night and takes an assigned room off', function () {
    $setup = bookingSetup();
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create(['room_type_id' => $setup['roomType']->id, 'arrival_date' => '2026-10-21', 'departure_date' => '2026-10-23']);
    app(AssignRoom::class)($setup['user'], $unit, $setup['rooms'][0]);

    $query = ['room_type_id' => $setup['roomType']->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22'];

    $this->actingAs($setup['user'])
        ->getJson(previewUrl($setup, 'reservations.free-units', $query))
        ->assertOk()
        ->assertExactJson(['units' => [['id' => $setup['rooms'][1]->id, 'label' => '102', 'reason' => null]]]);

    $this->getJson(previewUrl($setup, 'reservations.free-units', [...$query, 'arrival_date' => '2026-10-24', 'departure_date' => '2026-10-25']))
        ->assertJsonCount(2, 'units');
});

test('free units lists the beds of a dorm and nothing for a room type of another property', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->getJson(previewUrl($setup, 'reservations.free-units', ['room_type_id' => $setup['dorm']->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']))
        ->assertOk()
        ->assertJsonPath('units.0.label', 'D1 · Bed 1')
        ->assertJsonCount(3, 'units');

    $this->getJson(previewUrl($setup, 'reservations.free-units', ['room_type_id' => RoomType::factory()->create()->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']))
        ->assertOk()
        ->assertExactJson(['units' => []]);
});

test('free units checks its query and the preview routes stay in their property', function () {
    $setup = bookingSetup();
    $other = Property::factory()->create();

    $this->actingAs($setup['user'])->getJson(previewUrl($setup, 'reservations.free-units', ['room_type_id' => $setup['roomType']->id]))->assertUnprocessable();
    $this->getJson(route('reservations.free-units', ['property' => $other->code, 'room_type_id' => 1, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22']))->assertNotFound();
});

test('a quote adds the add-ons of the line and multiplies them by the beds', function () {
    $setup = bookingSetup();
    $perItem = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '50.00']);
    $nightly = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => '10.00', 'charging_unit' => 'per_guest_night']);

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, [
            'add_ons' => [['charge_item_id' => $perItem->id, 'quantity' => 2], ['charge_item_id' => $nightly->id]],
        ]))
        ->assertOk()
        ->assertJsonPath('addOnsTotal', '140.00')
        ->assertJsonPath('lineTotal', '200.00');

    $this->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, [
        'room_type_id' => $setup['dorm']->id,
        'rate_plan_id' => $setup['bedPlan']->id,
        'adults' => 1,
        'beds' => 2,
        'add_ons' => [['charge_item_id' => $perItem->id, 'unit_amount' => '20.00']],
    ]))->assertJsonPath('addOnsTotal', '40.00');
});

test('a quote leaves out an add-on it cannot price', function () {
    $setup = bookingSetup();
    $noPrice = ChargeItem::factory()->create(['property_id' => $setup['property']->id, 'default_amount' => null]);

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, ['add_ons' => [['charge_item_id' => $noPrice->id], ['charge_item_id' => ChargeItem::factory()->create()->id]]]))
        ->assertOk()
        ->assertJsonPath('addOnsTotal', '0.00');
});

test('a price override replaces the quote and is flagged', function () {
    $setup = bookingSetup();

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, ['override_total' => '150.00']))
        ->assertOk()
        ->assertJsonPath('unitTotal', '150.00')
        ->assertJsonPath('lineTotal', '150.00')
        ->assertJsonPath('isOverridden', true)
        ->assertJsonPath('nights.0.amount', '100.00');
});

test('a quote prices the children of a per guest add-on by age group', function () {
    $setup = bookingSetup();
    $property = $setup['property'];
    $infant = PropertyAgeGroup::factory()->create(['property_id' => $property->id, 'label' => 'Infant', 'min_age' => 0, 'max_age' => 3]);
    $child = PropertyAgeGroup::factory()->create(['property_id' => $property->id, 'label' => 'Child', 'min_age' => 4, 'max_age' => 11]);
    $item = ChargeItem::factory()->create(['property_id' => $property->id, 'default_amount' => '30.00', 'charging_unit' => 'per_guest']);
    ChargeItemChildPrice::factory()->free()->create(['charge_item_id' => $item->id, 'property_age_group_id' => $infant->id]);
    ChargeItemChildPrice::factory()->percent('50')->create(['charge_item_id' => $item->id, 'property_age_group_id' => $child->id]);

    $this->actingAs($setup['user'])
        ->postJson(previewUrl($setup, 'reservations.quote'), quoteBody($setup, ['adults' => 2, 'child_ages' => [6, 2], 'add_ons' => [['charge_item_id' => $item->id]]]))
        ->assertOk()
        ->assertJsonPath('addOnsTotal', '75.00');
});
