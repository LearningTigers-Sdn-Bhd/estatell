<?php

use App\Enums\TransferMode;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\RatePlanInclusion;
use App\Models\ReservationUnit;
use App\Models\TransferDriver;
use App\Models\TransferRoute;
use App\Models\TransferVehicle;
use App\Models\UnitAddOn;
use App\Models\UnitTransfer;
use App\Models\UnitTransferAddOn;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/TransferHelpers.php';

/** @param  array<string, mixed>  $fixture */
function transferBody(array $fixture, string $mode = 'shared', array $overrides = []): array
{
    return ['mode' => $mode, 'transfer_route_id' => $fixture['in']->id, 'pickup_time' => '10:30', 'flight_number' => 'ak 6231', ...$overrides];
}

test('a shared transfer in charges the route\'s per-guest price and records the flight number', function () {
    $fixture = transferFixture();

    $this->actingAs($fixture['owner'])
        ->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Transfer saved.']);

    $transfer = UnitTransfer::query()->sole();
    $addOn = UnitAddOn::query()->where('reservation_unit_id', $fixture['unit']->id)->sole();

    expect($transfer->mode)->toBe(TransferMode::Shared)
        ->and($transfer->pickup_time)->toBe('10:30:00')
        ->and($transfer->flight_number)->toBe('AK 6231')
        ->and($addOn->charge_item_id)->toBe($fixture['shared']->id)
        ->and($addOn->quantity)->toBe(3)
        ->and(UnitTransferAddOn::query()->where('unit_transfer_id', $transfer->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.transfer_set')->sole()->description)->toBe('Transfer in: Shared, Airport to hotel at 10:30.');
});

test('switching to private replaces the unposted shared charge, and in package removes it', function () {
    $fixture = transferFixture();
    $this->actingAs($fixture['owner'])->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture));

    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, 'private'))->assertSessionHasNoErrors();

    $addOn = UnitAddOn::query()->where('reservation_unit_id', $fixture['unit']->id)->sole();
    expect($addOn->charge_item_id)->toBe($fixture['private']->id)
        ->and($addOn->quantity)->toBe(1);

    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, 'included'))->assertSessionHasNoErrors();

    expect(UnitAddOn::query()->count())->toBe(0)
        ->and(UnitTransfer::query()->sole()->mode)->toBe(TransferMode::Included);
});

test('a new pick-up time keeps the charge, and a set that changes nothing logs nothing', function () {
    $fixture = transferFixture();
    $this->actingAs($fixture['owner'])->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture));
    $addOnId = UnitAddOn::query()->sole()->id;

    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['pickup_time' => '11:15']));
    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['pickup_time' => '11:15']));

    expect(UnitAddOn::query()->sole()->id)->toBe($addOnId)
        ->and(UnitTransfer::query()->sole()->pickup_time)->toBe('11:15:00')
        ->and(Activity::query()->where('event', 'reservation.transfer_set')->count())->toBe(2);
});

test('a package that includes the transfer item is not charged again', function () {
    $fixture = transferFixture();
    RatePlanInclusion::factory()->create(['rate_plan_id' => $fixture['unit']->rate_plan_id, 'charge_item_id' => $fixture['shared']->id]);

    $this->actingAs($fixture['owner'])->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture))->assertSessionHasNoErrors();

    expect(UnitTransfer::query()->count())->toBe(1)
        ->and(UnitAddOn::query()->count())->toBe(0);
});

test('a posted charge is kept on change, and the toast says to reverse it', function () {
    $fixture = transferFixture();
    $this->actingAs($fixture['owner'])->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture));
    UnitAddOn::query()->sole()->forceFill(['folio_line_id' => FolioLine::factory()->create()->id])->save();

    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, 'private'))
        ->assertInertiaFlash('toast', ['type' => 'warning', 'message' => 'Transfer saved. The old transfer charge is already posted. Reverse it on the folio if needed.']);

    expect(UnitAddOn::query()->where('reservation_unit_id', $fixture['unit']->id)->count())->toBe(2);
});

test('a route of the other direction, another property or turned off is refused and nothing is saved', function (Closure $route) {
    $fixture = transferFixture();

    $this->actingAs($fixture['owner'])
        ->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['transfer_route_id' => $route($fixture)->id]))
        ->assertSessionHasErrors(['transfer_route_id' => 'Pick an available route of this direction.']);

    expect(UnitTransfer::query()->count())->toBe(0)
        ->and(UnitAddOn::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.transfer_set')->count())->toBe(0);
})->with([
    'the other direction' => [fn (array $fixture) => $fixture['out']],
    'another property' => [fn (array $fixture) => TransferRoute::factory()->create()],
    'turned off' => [fn (array $fixture) => TransferRoute::factory()->for($fixture['property'])->create(['active' => false])],
]);

test('a transfer in cannot change once the room is checked in, but a transfer out can', function () {
    $fixture = transferFixture();
    $fixture['unit']->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();

    $this->actingAs($fixture['owner'])
        ->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture))
        ->assertSessionHasErrors(['form' => 'This room can no longer change this transfer.']);

    $this->put(transferSetUrl($fixture, $fixture['unit'], 'out'), transferBody($fixture, 'included', ['transfer_route_id' => $fixture['out']->id]))
        ->assertSessionHasNoErrors();

    expect(UnitTransfer::query()->sole()->direction->value)->toBe('out');
});

test('the request checks the time and the flight number', function () {
    $fixture = transferFixture();

    $this->actingAs($fixture['owner'])
        ->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['pickup_time' => 'noon', 'flight_number' => 'AK-6231!']))
        ->assertSessionHasErrors(['pickup_time', 'flight_number' => 'Use letters and numbers only, such as AK 6231.']);
});

test('clearing a transfer removes it and its unposted charge', function () {
    $fixture = transferFixture();
    $this->actingAs($fixture['owner'])->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture));

    $this->delete(route('reservations.units.transfers.clear', ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id, 'unit' => $fixture['unit']->id, 'direction' => 'in']))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Transfer removed.']);

    expect(UnitTransfer::query()->count())->toBe(0)
        ->and(UnitAddOn::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.transfer_cleared')->count())->toBe(1);
});

test('the routes need the feature, the manage permission and the property', function () {
    $off = transferFixture(featureOn: false);
    $this->actingAs($off['owner'])->put(transferSetUrl($off, $off['unit'], 'in'), transferBody($off))->assertNotFound();
    $this->get(route('reservations.transfers', [$off['property']->code, $off['reservation']->id]))->assertNotFound();

    $fixture = transferFixture();
    $viewer = User::factory()->for($fixture['property'])->withPermissions(['reservations.view'])->create();
    $this->actingAs($viewer)->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture))->assertForbidden();

    $other = transferFixture();
    $this->actingAs($fixture['owner'])
        ->put(route('reservations.units.transfers.set', ['property' => $fixture['property']->code, 'reservation' => $other['reservation']->id, 'unit' => $other['unit']->id, 'direction' => 'in']), transferBody($fixture))
        ->assertNotFound();
});

test('the Transfers tab lists both directions of each room, with the set transfer and the routes', function () {
    $fixture = transferFixture();
    UnitTransfer::factory()->create([
        'property_id' => $fixture['property']->id,
        'reservation_unit_id' => $fixture['unit']->id,
        'transfer_route_id' => $fixture['in']->id,
        'flight_number' => 'AK 6231',
        'transfer_driver_id' => TransferDriver::factory()->for($fixture['property'])->create(['name' => 'Ah Seng', 'subcontractor' => 'Kina Tours'])->id,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('reservations.transfers', [$fixture['property']->code, $fixture['reservation']->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reservations/show')
            ->where('tab', 'transfers')
            ->where('hasTransfers', true)
            ->has('transferRows', 2)
            ->where('transferRows.0.direction', 'in')
            ->where('transferRows.0.mode', 'shared')
            ->where('transferRows.0.flightNumber', 'AK 6231')
            ->where('transferRows.0.driver', 'Ah Seng (Kina Tours)')
            ->where('transferRows.0.canEdit', true)
            ->where('transferRows.1.direction', 'out')
            ->where('transferRows.1.mode', null)
            ->has('transferRoutes', 2));
});

test('the run sheet lists the transfers of a date by pick-up time, and staff assign a vehicle and a driver', function () {
    $fixture = transferFixture();
    $in = UnitTransfer::factory()->create(['property_id' => $fixture['property']->id, 'reservation_unit_id' => $fixture['unit']->id, 'transfer_route_id' => $fixture['in']->id, 'pickup_time' => '14:00']);
    $earlier = transferFixture();
    $otherUnit = ReservationUnit::factory()->for($fixture['reservation'])->create(['property_id' => $fixture['property']->id, 'arrival_date' => '2026-10-18', 'departure_date' => '2026-10-20', 'adults' => 4, 'children' => 0, 'status' => UnitStatus::Confirmed]);
    UnitTransfer::factory()->create(['property_id' => $fixture['property']->id, 'reservation_unit_id' => $otherUnit->id, 'direction' => 'out', 'mode' => 'private', 'transfer_route_id' => $fixture['out']->id, 'pickup_time' => '09:00']);
    UnitTransfer::factory()->create(['property_id' => $earlier['property']->id, 'reservation_unit_id' => $earlier['unit']->id, 'transfer_route_id' => $earlier['in']->id]);
    $vehicle = TransferVehicle::factory()->for($fixture['property'])->create();
    $driver = TransferDriver::factory()->for($fixture['property'])->create();

    $this->actingAs($fixture['owner'])
        ->get(route('transfers.index', [$fixture['property']->code, 'date' => '2026-10-20']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('transfers/index')
            ->has('rows', 2)
            ->where('rows.0.pickupTime', '09:00')
            ->where('rows.0.direction', 'out')
            ->where('rows.0.pax', 4)
            ->where('rows.1.id', $in->id)
            ->where('rows.1.pax', 3)
            ->where('canAssign', true));

    $this->put(route('transfers.assign', [$fixture['property']->code, $in->id]), ['transfer_vehicle_id' => $vehicle->id, 'transfer_driver_id' => $driver->id])
        ->assertRedirect();

    expect($in->refresh()->transfer_vehicle_id)->toBe($vehicle->id)
        ->and($in->transfer_driver_id)->toBe($driver->id)
        ->and(Activity::query()->where('event', 'reservation.transfer_assigned')->count())->toBe(1);
});

test('assigning refuses a vehicle of another property and a transfer of another property is a 404', function () {
    $fixture = transferFixture();
    $transfer = UnitTransfer::factory()->create(['property_id' => $fixture['property']->id, 'reservation_unit_id' => $fixture['unit']->id, 'transfer_route_id' => $fixture['in']->id]);

    $this->actingAs($fixture['owner'])
        ->put(route('transfers.assign', [$fixture['property']->code, $transfer->id]), ['transfer_vehicle_id' => TransferVehicle::factory()->create()->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Pick an available vehicle.']);

    expect($transfer->refresh()->transfer_vehicle_id)->toBeNull();

    $other = transferFixture();
    $otherTransfer = UnitTransfer::factory()->create(['property_id' => $other['property']->id, 'reservation_unit_id' => $other['unit']->id, 'transfer_route_id' => $other['in']->id]);
    $this->put(route('transfers.assign', [$fixture['property']->code, $otherTransfer->id]), [])->assertNotFound();
});

test('the bags are saved with the transfer, and a change of bags alone is saved and logged', function () {
    $fixture = transferFixture();
    $this->actingAs($fixture['owner']);

    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['bags' => '3']))->assertSessionHasNoErrors();
    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['bags' => '4']))->assertSessionHasNoErrors();
    $this->put(transferSetUrl($fixture, $fixture['unit'], 'in'), transferBody($fixture, overrides: ['bags' => '100']))->assertSessionHasErrors('bags');

    expect(UnitTransfer::query()->sole()->bags)->toBe(4)
        ->and(Activity::query()->where('event', 'reservation.transfer_set')->count())->toBe(2);
});
