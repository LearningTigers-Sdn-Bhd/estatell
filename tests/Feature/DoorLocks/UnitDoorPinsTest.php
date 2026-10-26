<?php

use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Models\Room;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/DoorLockSetup.php';

function unitPage(User $user, $unit)
{
    return test()->actingAs($user)->get(route('reservations.show', [
        'property' => $unit->property,
        'reservation' => $unit->reservation_id,
        'unit' => $unit->id,
    ]));
}

it('shows the stay PIN on the reservation page of a checked-in unit', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page
        ->where('doorPins.passcodes.0.code', '482913')
        ->where('doorPins.passcodes.0.status.value', 'active')
        ->where('doorPins.locksWithoutPin', []));
});

it('names the lock when a checked-in room has no PIN yet', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup();
    $unit = doorLockStay($property, $room);

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page
        ->where('doorPins.passcodes', [])
        ->where('doorPins.locksWithoutPin.0.name', $lock->name)
        ->where('doorPins.locksWithoutPin.0.reason', null));
});

it('shows nothing when no lock is linked to the room', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup();
    $other = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);
    $unit = doorLockStay($property, $other);

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page->where('doorPins', null));
});

it('shows nothing while the Door locks feature is off', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(feature: false);
    $unit = doorLockStay($property, $room);

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page->where('doorPins', null));
});

it('lets front desk staff see the PIN but not staff without check-in rights', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);

    $frontDesk = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView, PermissionEnum::FrontDeskCheckIn])->create();
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();

    unitPage($frontDesk, $unit)->assertInertia(fn (Assert $page) => $page->where('doorPins.passcodes.0.code', '482913'));
    unitPage($viewer, $unit)->assertInertia(fn (Assert $page) => $page->where('doorPins', null));
});

it('tells staff to give the guest the newest PIN after an extension', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page->where('doorPins.sendNewPin', false));

    $unit->update(['departure_date' => '2026-10-18']);
    app(SyncStayPasscodes::class)($unit->refresh());

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page
        ->where('doorPins.sendNewPin', true)
        ->has('doorPins.passcodes', 2));
});

it('says why a checked-in room has no PIN', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'connection' => $connection] = doorLockSetup();
    $unit = doorLockStay($property, $room);

    $this->travelTo(now()->addDays(5));
    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page
        ->where('doorPins.locksWithoutPin.0.reason', 'The stay has already ended, so a PIN would be out of date.'));

    $this->travelBack();
    $connection->update(['enabled' => false]);
    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page
        ->where('doorPins.locksWithoutPin.0.reason', 'Issue PINs automatically is off.'));
});

it('keeps the Delete PIN note on a revoked PIN only while its window is open', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    app(SyncStayPasscodes::class)($unit->refresh());

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page
        ->where('doorPins.passcodes.0.status.value', 'revoked')
        ->where('doorPins.passcodes.0.revokeNote', fn ($note) => str_contains($note, '482913')));

    $this->travelTo(now()->addDays(3));

    unitPage($owner, $unit)->assertInertia(fn (Assert $page) => $page->where('doorPins.passcodes.0.revokeNote', null));
});
