<?php

use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\TransferDriver;
use App\Models\TransferVehicle;
use App\Models\UnitTransfer;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/TransferHelpers.php';

/**
 * The fixture with three transfers in on 20 Oct (09:40, 10:05 and 10:20), a 10-seat van and a driver.
 *
 * @return array<string, mixed>
 */
function transferRunFixture(): array
{
    $fixture = transferFixture();
    $property = $fixture['property'];
    $fixture['van'] = TransferVehicle::factory()->for($property)->create(['name' => 'Van 1', 'seats' => 10]);
    $fixture['driver'] = TransferDriver::factory()->for($property)->create(['name' => 'Ali']);
    $fixture['transfers'] = collect(['09:40', '10:05', '10:20'])->map(function (string $time) use ($fixture, $property): UnitTransfer {
        $unit = ReservationUnit::factory()->for($fixture['reservation'])->create(['property_id' => $property->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22', 'status' => UnitStatus::Confirmed]);

        return UnitTransfer::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $unit->id, 'transfer_route_id' => $fixture['in']->id, 'pickup_time' => $time]);
    })->all();

    return $fixture;
}

/** @param  array<string, mixed>  $fixture */
function transferRunUrl(array $fixture): string
{
    return route('transfers.run', $fixture['property']->code);
}

test('ticked transfers go on one van run with one driver and one pick-up time, and each change is logged', function () {
    $fixture = transferRunFixture();
    [$first, $second, $third] = $fixture['transfers'];

    $this->actingAs($fixture['owner'])->put(transferRunUrl($fixture), [
        'transfer_ids' => [$first->id, $second->id],
        'transfer_vehicle_id' => $fixture['van']->id,
        'transfer_driver_id' => $fixture['driver']->id,
        'pickup_time' => '10:00',
    ])->assertSessionHas('inertia.flash_data.toast.message', '2 transfers put on the run.');

    expect([$first->fresh()->transfer_vehicle_id, $first->fresh()->transfer_driver_id, $first->fresh()->pickup_time])->toBe([$fixture['van']->id, $fixture['driver']->id, '10:00:00'])
        ->and($second->fresh()->pickup_time)->toBe('10:00:00')
        ->and($third->fresh()->transfer_vehicle_id)->toBeNull()
        ->and(Activity::query()->where('event', 'reservation.transfer_assigned')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'reservation.transfer_time_changed')->pluck('description')->all())->toBe(['Transfer in pick-up time 09:40 → 10:00.', 'Transfer in pick-up time 10:05 → 10:00.']);

    $this->put(transferRunUrl($fixture), ['transfer_ids' => [$third->id], 'transfer_vehicle_id' => $fixture['van']->id])->assertSessionHasNoErrors();
    expect($third->fresh()->pickup_time)->toBe('10:20:00')
        ->and($third->fresh()->transfer_driver_id)->toBeNull();
});

test('one refused transfer leaves the whole run unchanged', function () {
    $fixture = transferRunFixture();
    $other = transferRunFixture();
    [$first, $second] = $fixture['transfers'];
    $fixture['van']->update(['active' => false]);
    $this->actingAs($fixture['owner']);

    $this->put(transferRunUrl($fixture), ['transfer_ids' => [$first->id, $second->id], 'transfer_vehicle_id' => $fixture['van']->id, 'pickup_time' => '10:00'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Pick an available vehicle.');
    $this->put(transferRunUrl($fixture), ['transfer_ids' => [$first->id, $other['transfers'][0]->id], 'transfer_driver_id' => $fixture['driver']->id])
        ->assertSessionHas('inertia.flash_data.toast.message', 'A transfer is no longer on the run sheet. Reload the page.');

    expect($first->fresh()->pickup_time)->toBe('09:40:00')
        ->and($first->fresh()->transfer_driver_id)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['reservation.transfer_assigned', 'reservation.transfer_time_changed'])->count())->toBe(0);
});

test('putting transfers on a run needs reservations.manage and a list of transfers', function () {
    $fixture = transferRunFixture();
    $viewer = User::factory()->for($fixture['property'])->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($viewer)->put(transferRunUrl($fixture), ['transfer_ids' => [$fixture['transfers'][0]->id]])->assertForbidden();
    $this->actingAs($fixture['owner'])->put(transferRunUrl($fixture), ['transfer_ids' => []])->assertSessionHasErrors('transfer_ids');
    $this->put(transferRunUrl($fixture), ['transfer_ids' => [$fixture['transfers'][0]->id], 'pickup_time' => '25:00'])->assertSessionHasErrors('pickup_time');
});
