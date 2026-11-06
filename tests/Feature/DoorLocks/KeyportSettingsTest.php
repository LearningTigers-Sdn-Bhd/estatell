<?php

use App\Enums\KeyportJobStatus;
use App\Models\DoorLock;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use App\Models\KeyportPairingCode;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/KeyportSetup.php';

it('shows the PCs, eLock locks and recent jobs to staff who manage settings', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'device' => $device] = keyportElockSetup();
    $device->forceFill(['last_seen_at' => now(), 'reader_state' => [['kind' => 'rfrw', 'state' => 'connected']], 'client_code_present' => true])->save();
    KeyportJob::factory()->create(['property_id' => $property->id, 'door_lock_id' => $lock->id]);

    $this->actingAs($owner)->get(route('settings.keyport.index', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/keyport/index')
            ->where('devices.0.status.value', 'online')
            ->where('devices.0.readerNote', 'Card reader ready.')
            ->where('locks.0.lockNo', '1.1.101')
            ->has('locks', 1)
            ->where('jobs.0.status.value', 'pending'));
});

it('hides the page from staff without settings permission and when Door locks is off', function () {
    ['property' => $property] = keyportElockSetup();
    $clerk = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($clerk)->get(route('settings.keyport.index', $property))->assertForbidden();

    ['owner' => $owner, 'property' => $off] = doorLockSetup(feature: false);
    $this->actingAs($owner)->get(route('settings.keyport.index', $off))->assertNotFound();
});

it('flashes a pairing code that the PC can use', function () {
    ['owner' => $owner, 'property' => $property] = keyportElockSetup();

    $this->actingAs($owner)->post(route('settings.keyport.codes.store', $property))
        ->assertRedirect(route('settings.keyport.index', $property));

    expect(KeyportPairingCode::query()->where('property_id', $property->id)->count())->toBe(1);
});

it('revokes a PC of the property and never one of another', function () {
    ['owner' => $owner, 'property' => $property, 'device' => $device] = keyportElockSetup();
    $foreign = KeyportDevice::factory()->create();

    $this->actingAs($owner)->post(route('settings.keyport.devices.revoke', [$property, $foreign]))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.keyport.devices.revoke', [$property, $device]))->assertRedirect();

    expect($device->refresh()->revoked_at)->not->toBeNull()->and($foreign->refresh()->revoked_at)->toBeNull();
});

it('adds an eLock lock and validates the lock number', function () {
    ['owner' => $owner, 'property' => $property] = keyportElockSetup();
    $room = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);

    $this->actingAs($owner)->post(route('settings.keyport.locks.store', $property), ['name' => 'Room 102', 'lock_no' => '0102', 'room_id' => $room->id])->assertSessionHasErrors('lock_no');
    $this->actingAs($owner)->post(route('settings.keyport.locks.store', $property), ['name' => 'Room 102', 'lock_no' => '1.300.102'])->assertSessionHasErrors('lock_no');
    $this->actingAs($owner)->post(route('settings.keyport.locks.store', $property), ['name' => 'Room 102', 'lock_no' => '1.1.102', 'room_id' => $room->id])->assertSessionHasNoErrors();

    expect(DoorLock::query()->where('lock_no', '1.1.102')->sole()->room_id)->toBe($room->id);
});

it('cancels a pending job of the property only', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = keyportElockSetup();
    $job = KeyportJob::factory()->create(['property_id' => $property->id, 'door_lock_id' => $lock->id]);
    $foreign = KeyportJob::factory()->create();

    $this->actingAs($owner)->post(route('settings.keyport.jobs.cancel', [$property, $foreign]))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.keyport.jobs.cancel', [$property, $job]))->assertRedirect();

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Cancelled);
});

it('refuses remote unlock, clock and health for an eLock lock', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = keyportElockSetup();
    fakeTtlock();

    foreach (['unlock', 'clock', 'health'] as $action) {
        $this->actingAs($owner)->post(route("settings.door-locks.{$action}", [$property, $lock]))->assertRedirect();
    }

    expect(Http::recorded())->toHaveCount(0);
});

it('makes a key card from the stay page and shows the panel', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();
    $reservation = $unit->reservation;

    $this->actingAs($owner)->post(route('reservations.units.key-cards.store', [$property, $reservation, $unit]), ['door_lock_id' => $lock->id, 'type' => 'guest_card.issue'])->assertRedirect();
    $this->actingAs($owner)->post(route('reservations.units.key-cards.store', [$property, $reservation, $unit]), ['door_lock_id' => $lock->id, 'type' => 'guest_card.read'])->assertSessionHasErrors('type');

    expect(KeyportJob::query()->sole()->reservation_unit_id)->toBe($unit->id);

    $this->actingAs($owner)->get(route('reservations.show', [$property, $reservation, 'unit' => $unit->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('keyCards.locks.0.name', $lock->name)
            ->where('keyCards.canMake', true)
            ->where('keyCards.syncing', true)
            ->where('keyCards.jobs.0.status.value', 'pending'));
});
