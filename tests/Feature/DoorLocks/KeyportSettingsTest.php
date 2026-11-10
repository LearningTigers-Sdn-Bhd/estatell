<?php

use App\Actions\DoorLocks\CreateKeyportJob;
use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
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

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/door-locks/index')
            ->where('keyport.devices.0.status.value', 'online')
            ->where('keyport.devices.0.readerNote', 'Card reader ready.')
            ->where('keyport.locks.data.0.lockNo', '1.1.101')
            ->where('keyport.lockTotal', 1)
            ->where('keyport.jobs.data.0.status.value', 'pending'));
});

it('sends the old Key cards URL to the Door locks page', function () {
    ['owner' => $owner, 'property' => $property] = keyportElockSetup();

    $this->actingAs($owner)->get(route('settings.keyport.index', $property))->assertRedirect(route('settings.door-locks.edit', $property));
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
        ->assertRedirect(route('settings.door-locks.edit', $property));

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

it('removes a revoked PC but never a live one, keeping its job history', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'device' => $device] = keyportElockSetup();
    $job = KeyportJob::factory()->claimedBy($device)->create(['door_lock_id' => $lock->id]);

    $this->actingAs($owner)->delete(route('settings.keyport.devices.destroy', [$property, $device]))->assertRedirect();
    expect($device->fresh())->not->toBeNull();

    $device->forceFill(['revoked_at' => now()])->save();
    $this->actingAs($owner)->delete(route('settings.keyport.devices.destroy', [$property, $device]))->assertRedirect();

    expect($device->fresh())->toBeNull()->and($job->fresh()->device_id)->toBeNull();
});

it('pages the eLock locks and card jobs on their own parameters and searches locks on the server', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = keyportElockSetup();

    foreach (range(1, 14) as $n) {
        DoorLock::factory()->elock('2.1.'.$n)->for($property)->create(['name' => 'Annex '.$n, 'room_id' => null]);
    }

    KeyportJob::factory()->count(12)->create(['property_id' => $property->id, 'door_lock_id' => $lock->id]);
    $url = route('settings.door-locks.edit', $property);

    $this->actingAs($owner)->get($url)
        ->assertInertia(fn (Assert $page) => $page
            ->where('keyport.lockTotal', 15)->has('keyport.locks.data', 10)->where('keyport.locks.meta.last_page', 2)
            ->has('keyport.jobs.data', 10)->where('keyport.jobs.meta.last_page', 2));

    $this->actingAs($owner)->get($url.'?locks_page=2')
        ->assertInertia(fn (Assert $page) => $page->has('keyport.locks.data', 5)->has('keyport.jobs.data', 10));

    $this->actingAs($owner)->get($url.'?lock_search=2.1.7')
        ->assertInertia(fn (Assert $page) => $page->where('keyport.lockSearch', '2.1.7')->has('keyport.locks.data', 1)->where('keyport.lockTotal', 15));
});

it('edits an eLock lock but refuses a taken number, a taken room and a TTLock lock', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'lock' => $lock] = keyportElockSetup();
    $free = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);
    $other = DoorLock::factory()->elock('1.1.102')->for($property)->create(['room_id' => $free->id]);
    $ttlock = DoorLock::factory()->for($property)->create(['room_id' => null]);

    $this->actingAs($owner)->put(route('settings.keyport.locks.update', [$property, $lock]), ['name' => 'Room 101 door', 'lock_no' => '1.1.101', 'room_id' => $room->id])->assertRedirect();
    expect($lock->fresh()->name)->toBe('Room 101 door');

    $this->actingAs($owner)->put(route('settings.keyport.locks.update', [$property, $lock]), ['name' => 'X', 'lock_no' => '1.1.102', 'room_id' => $room->id]);
    $this->actingAs($owner)->put(route('settings.keyport.locks.update', [$property, $lock]), ['name' => 'X', 'lock_no' => '1.1.103', 'room_id' => $free->id]);
    $this->actingAs($owner)->put(route('settings.keyport.locks.update', [$property, $ttlock]), ['name' => 'X', 'lock_no' => '1.1.104', 'room_id' => '']);
    expect($lock->fresh()->name)->toBe('Room 101 door')->and($lock->fresh()->lock_no)->toBe('1.1.101')->and($ttlock->fresh()->lock_no)->toBeNull();

    $this->actingAs($owner)->put(route('settings.keyport.locks.update', [$property, $lock]), ['name' => 'Moved', 'lock_no' => '1.1.201', 'room_id' => '']);
    expect($lock->fresh())->name->toBe('Moved')->lock_no->toBe('1.1.201')->room_id->toBeNull()->and($other->fresh()->lock_no)->toBe('1.1.102');
});

it('removes an eLock lock but not one with a waiting job or a card held by a checked-in guest', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();
    $url = route('settings.keyport.locks.destroy', [$property, $lock]);

    $waiting = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue);
    $this->actingAs($owner)->delete($url);
    expect($lock->fresh())->not->toBeNull();

    $waiting->forceFill(['status' => KeyportJobStatus::Succeeded, 'serial' => 'A1B2C3D4', 'finished_at' => now()])->save();
    $this->actingAs($owner)->delete($url);
    expect($lock->fresh())->not->toBeNull();

    $erase = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Erase);
    $erase->forceFill(['status' => KeyportJobStatus::Succeeded, 'finished_at' => now()])->save();
    $this->actingAs($owner)->delete($url)->assertRedirect();

    expect($lock->fresh())->toBeNull()->and($waiting->fresh()->door_lock_id)->toBeNull();
});

it('never edits or removes a lock of another property', function () {
    ['owner' => $owner, 'property' => $property] = keyportElockSetup();
    ['lock' => $foreign] = keyportElockSetup();

    $this->actingAs($owner)->delete(route('settings.keyport.locks.destroy', [$property, $foreign]))->assertNotFound();
    $this->actingAs($owner)->put(route('settings.keyport.locks.update', [$property, $foreign]), ['name' => 'X', 'lock_no' => '1.1.150', 'room_id' => ''])->assertNotFound();
    expect($foreign->fresh())->not->toBeNull();
});
