<?php

use App\Actions\Reservations\AddGroupAddOn;
use App\Actions\Reservations\AddOnResult;
use App\Actions\Reservations\GroupAddOnResult;
use App\Data\Reservations\NewAddOnData;
use App\Enums\ChargingUnit;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitAddOn;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A group of three Confirmed Deluxe Twin units with different guests and nights, and a Breakfast charged per guest
 * per night at 30.00. `$thirdStatus` sets the status of the third unit.
 *
 * @return array{property: Property, user: User, reservation: Reservation, units: list<ReservationUnit>, item: ChargeItem}
 */
function groupAddOnSetup(?UnitStatus $thirdStatus = null): array
{
    $property = Property::factory()->create();
    PropertySetting::factory()->for($property)->create();
    $roomType = RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Deluxe Twin']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = fn (array $attributes): ReservationUnit => ReservationUnit::factory()->create([
        'reservation_id' => $reservation->id,
        'room_type_id' => $roomType->id,
        ...$attributes,
    ]);
    $units = [
        $unit(['arrival_date' => '2026-11-11', 'departure_date' => '2026-11-12', 'adults' => 2]),
        $unit(['arrival_date' => '2026-11-11', 'departure_date' => '2026-11-13', 'adults' => 3]),
        $unit(['arrival_date' => '2026-11-12', 'departure_date' => '2026-11-14', 'adults' => 1, 'status' => $thirdStatus ?? UnitStatus::Confirmed]),
    ];
    $item = ChargeItem::factory()->create([
        'property_id' => $property->id,
        'name' => 'Breakfast',
        'default_amount' => '30.00',
        'charging_unit' => ChargingUnit::PerGuestNight,
        'allow_override' => true,
    ]);

    return ['property' => $property, 'user' => User::factory()->for($property)->create(), 'reservation' => $reservation, 'units' => $units, 'item' => $item];
}

/**
 * @param  array{property: Property, reservation: Reservation}  $setup
 */
function groupAddOnUrl(array $setup, string $route = 'reservations.add-ons.store'): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id]);
}

test('each unit gets its own rows for its own guests and nights', function () {
    $setup = groupAddOnSetup();
    [$first, $second, $third] = $setup['units'];

    $result = app(AddGroupAddOn::class)($setup['user'], $setup['reservation'], [$first->id, $second->id, $third->id], new NewAddOnData(chargeItemId: $setup['item']->id));

    expect($result->isRefused())->toBeFalse()
        ->and($result->units)->toBe(3)
        ->and($result->addOns)->toHaveCount(5)
        ->and(UnitAddOn::query()->where('reservation_unit_id', $first->id)->pluck('quantity')->all())->toBe([2])
        ->and(UnitAddOn::query()->where('reservation_unit_id', $second->id)->pluck('quantity')->all())->toBe([3, 3])
        ->and(UnitAddOn::query()->where('reservation_unit_id', $third->id)->orderBy('service_date')->pluck('service_date')->map->toDateString()->all())->toBe(['2026-11-12', '2026-11-13'])
        ->and(Activity::query()->where('event', 'reservation.add_on_added')->count())->toBe(3);
});

test('one refused unit refuses the whole save, names the unit and writes nothing', function () {
    $setup = groupAddOnSetup(UnitStatus::Cancelled);
    [$first, , $third] = $setup['units'];

    $result = app(AddGroupAddOn::class)($setup['user'], $setup['reservation'], [$first->id, $third->id], new NewAddOnData(chargeItemId: $setup['item']->id));

    expect($result->refusal)->toBe(AddOnResult::REFUSAL_UNIT_NOT_OPEN)
        ->and($result->failedUnitId)->toBe($third->id)
        ->and($result->failedUnit)->toBe('Deluxe Twin · Not assigned')
        ->and(UnitAddOn::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('units of another reservation are ignored and none left is refused', function () {
    $setup = groupAddOnSetup();
    $foreign = ReservationUnit::factory()->create(['reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id]);

    $result = app(AddGroupAddOn::class)($setup['user'], $setup['reservation'], [$foreign->id], new NewAddOnData(chargeItemId: $setup['item']->id));

    expect($result->refusal)->toBe(GroupAddOnResult::REFUSAL_NO_UNITS)
        ->and(UnitAddOn::query()->count())->toBe(0);
});

test('the save route adds to every picked unit and names the count in the toast', function () {
    $setup = groupAddOnSetup();
    $user = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'reservations.manage'])->create();

    $this->actingAs($user)
        ->post(groupAddOnUrl($setup), ['unit_ids' => array_map(fn (ReservationUnit $unit): int => $unit->id, $setup['units']), 'charge_item_id' => $setup['item']->id])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Breakfast added to 3 units.');

    expect(UnitAddOn::query()->count())->toBe(5);
});

test('a refused unit comes back as a form error that starts with the unit', function () {
    $setup = groupAddOnSetup(UnitStatus::Cancelled);
    $user = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'reservations.manage'])->create();

    $this->actingAs($user)
        ->post(groupAddOnUrl($setup), ['unit_ids' => array_map(fn (ReservationUnit $unit): int => $unit->id, $setup['units']), 'charge_item_id' => $setup['item']->id])
        ->assertSessionHasErrors(['form' => 'Deluxe Twin · Not assigned: This unit is closed, so it cannot change.']);

    expect(UnitAddOn::query()->count())->toBe(0);
});

test('the preview shows each unit with its guests, nights and total before tax', function () {
    $setup = groupAddOnSetup();
    $user = User::factory()->for($setup['property'])->withPermissions(['reservations.view', 'reservations.manage'])->create();

    $this->actingAs($user)
        ->postJson(groupAddOnUrl($setup, 'reservations.add-ons.preview'), ['unit_ids' => array_map(fn (ReservationUnit $unit): int => $unit->id, $setup['units']), 'charge_item_id' => $setup['item']->id, 'unit_amount' => '25.00'])
        ->assertOk()
        ->assertJsonPath('lines.0.guests', 2)
        ->assertJsonPath('lines.0.nights', 1)
        ->assertJsonPath('lines.0.total', '50.00')
        ->assertJsonPath('lines.1.nights', 2)
        ->assertJsonPath('lines.1.total', '150.00')
        ->assertJsonPath('lines.2.total', '50.00')
        ->assertJsonPath('total', '250.00');

    expect(UnitAddOn::query()->count())->toBe(0);
});

test('the group add-on needs reservations.manage and the reservation\'s property', function () {
    $setup = groupAddOnSetup();
    $body = ['unit_ids' => [$setup['units'][0]->id], 'charge_item_id' => $setup['item']->id];
    $viewer = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($viewer)->post(groupAddOnUrl($setup), $body)->assertForbidden();
    $this->actingAs($viewer)->postJson(groupAddOnUrl($setup, 'reservations.add-ons.preview'), $body)->assertForbidden();

    $other = User::factory()->for(Property::factory()->create())->withPermissions(['reservations.view', 'reservations.manage'])->create();
    $this->actingAs($other)
        ->post(route('reservations.add-ons.store', ['property' => $other->property->code, 'reservation' => $setup['reservation']->id]), $body)
        ->assertNotFound();

    expect(UnitAddOn::query()->count())->toBe(0);
});
