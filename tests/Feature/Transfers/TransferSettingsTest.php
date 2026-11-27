<?php

use App\Models\ChargeItem;
use App\Models\TransferDriver;
use App\Models\TransferRoute;
use App\Models\TransferVehicle;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/TransferHelpers.php';

test('a route is added with its prices and the toast names it', function () {
    $fixture = transferFixture();

    $this->actingAs($fixture['owner'])
        ->post(route('settings.transfers.routes.store', $fixture['property']->code), [
            'direction' => 'in',
            'name' => 'Sandakan jetty to hotel',
            'place' => 'Sandakan jetty',
            'minutes' => 25,
            'shared_charge_item_id' => $fixture['shared']->id,
            'private_charge_item_id' => '',
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Route added.']);

    $route = TransferRoute::query()->where('name', 'Sandakan jetty to hotel')->sole();

    expect($route->property_id)->toBe($fixture['property']->id)
        ->and($route->minutes)->toBe(25)
        ->and($route->shared_charge_item_id)->toBe($fixture['shared']->id)
        ->and($route->private_charge_item_id)->toBeNull()
        ->and($route->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'transfer_route.created')->count())->toBe(1);
});

test('a route refuses a taken name and a price that is not an active fixed-price item of the property', function () {
    $fixture = transferFixture();
    $foreign = ChargeItem::factory()->create(['default_amount' => '10.00']);

    $this->actingAs($fixture['owner'])
        ->post(route('settings.transfers.routes.store', $fixture['property']->code), [
            'direction' => 'in',
            'name' => 'AIRPORT TO HOTEL',
            'place' => 'Airport',
            'shared_charge_item_id' => $foreign->id,
        ])
        ->assertSessionHasErrors([
            'name' => 'Another route already uses this name.',
            'shared_charge_item_id' => 'Pick an active charge item with a fixed price.',
        ]);
});

test('the Available switch turns a route off, and an edit without it keeps it', function () {
    $fixture = transferFixture();
    $url = route('settings.transfers.routes.update', [$fixture['property']->code, $fixture['in']->id]);
    $row = ['direction' => 'in', 'name' => 'Airport to hotel', 'place' => 'Sandakan Airport'];

    $this->actingAs($fixture['owner'])->put($url, [...$row, 'active' => false])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Airport to hotel is unavailable.']);
    $this->put($url, [...$row, 'place' => 'SDK Airport'])->assertSessionHasNoErrors();

    expect($fixture['in']->refresh()->active)->toBeFalse()
        ->and($fixture['in']->place)->toBe('SDK Airport');
});

test('vehicles and drivers are added and edited, and a driver can name a subcontractor', function () {
    $fixture = transferFixture();
    $code = $fixture['property']->code;

    $this->actingAs($fixture['owner'])
        ->post(route('settings.transfers.vehicles.store', $code), ['name' => 'Van 1', 'plate' => 'SA 1234 B', 'seats' => 10])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Vehicle added.']);
    $this->post(route('settings.transfers.drivers.store', $code), ['name' => 'Ah Seng', 'phone' => '+60 12 345 6789', 'subcontractor' => 'Kina Tours'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Driver added.']);

    $vehicle = TransferVehicle::query()->sole();
    $driver = TransferDriver::query()->sole();
    expect($vehicle->seats)->toBe(10)
        ->and($driver->subcontractor)->toBe('Kina Tours');

    $this->put(route('settings.transfers.drivers.update', [$code, $driver->id]), ['name' => 'Ah Seng', 'subcontractor' => ''])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Driver saved.']);

    expect($driver->refresh()->subcontractor)->toBeNull()
        ->and($driver->active)->toBeTrue();
});

test('the settings pages list the rows and need the feature, the permission and the property', function () {
    $fixture = transferFixture();
    TransferDriver::factory()->for($fixture['property'])->create(['name' => 'Ali']);

    $this->actingAs($fixture['owner'])
        ->get(route('settings.transfers', $fixture['property']->code))
        ->assertRedirect(route('settings.transfers.routes.index', $fixture['property']->code));
    $this->get(route('settings.transfers.drivers.index', $fixture['property']->code))
        ->assertInertia(fn ($page) => $page->component('settings/transfers/drivers')->where('drivers.0.label', 'Ali'));

    $other = transferFixture();
    $this->put(route('settings.transfers.routes.update', [$fixture['property']->code, $other['in']->id]), ['direction' => 'in', 'name' => 'X', 'place' => 'Y'])
        ->assertNotFound();

    $staff = User::factory()->for($fixture['property'])->withPermissions(['reservations.view'])->create();
    $this->actingAs($staff)->get(route('settings.transfers.routes.index', $fixture['property']->code))->assertForbidden();

    $off = transferFixture(featureOn: false);
    $this->actingAs($off['owner'])->get(route('settings.transfers.routes.index', $off['property']->code))->assertNotFound();
});
