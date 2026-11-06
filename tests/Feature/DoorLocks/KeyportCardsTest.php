<?php

use App\Actions\DoorLocks\AddElockLock;
use App\Actions\DoorLocks\CancelKeyportJob;
use App\Actions\DoorLocks\CreateKeyportJob;
use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Events\StayCheckedIn;
use App\Features\DoorLocks;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use App\Models\Property;
use App\Models\Room;
use App\Services\DoorLocks\StayLocks;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/KeyportSetup.php';

it('adds an eLock lock by number and refuses a repeated number or a taken room', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = keyportElockSetup();
    $free = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);
    $other = Room::factory()->create(['property_id' => Property::factory()->create()->id]);

    $added = app(AddElockLock::class)($owner, $property, 'Room 102', '1.1.102', $free);

    expect($added)->toBeInstanceOf(DoorLock::class)
        ->and($added->provider->value)->toBe('elock')
        ->and($added->ttlock_lock_id)->toBeNull()
        ->and(app(AddElockLock::class)($owner, $property, 'Again', '1.1.102', null))->toBeFalse()
        ->and(app(AddElockLock::class)($owner, $property, 'Taken', '1.1.200', $room))->toBeFalse()
        ->and(app(AddElockLock::class)($owner, $property, 'Foreign', '1.1.300', $other))->toBeFalse();
});

it('keeps TTLock code away from eLock locks', function () {
    ['property' => $property, 'room' => $room, 'unit' => $unit] = keyportElockSetup();
    DoorLock::factory()->for($property)->create(['room_id' => null]);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit);

    expect(app(StayLocks::class)->current($unit))->toHaveCount(0)
        ->and(app(StayLocks::class)->currentElock($unit))->toHaveCount(1)
        ->and(DoorPasscode::query()->count())->toBe(0)
        ->and(ttlockCalls('/v3/keyboardPwd/get'))->toBe(0);
});

it('makes an issue job with the lock number and local departure time', function () {
    ['owner' => $owner, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();
    $unit->update(['departure_time' => '12:00:00']);

    $job = app(CreateKeyportJob::class)($owner, $unit->refresh(), $lock, KeyportJobType::Issue);

    expect($job)->toBeInstanceOf(KeyportJob::class)
        ->and($job->status)->toBe(KeyportJobStatus::Pending)
        ->and($job->door_lock_id)->toBe($lock->id)
        ->and($job->reservation_unit_id)->toBe($unit->id)
        ->and($job->payload['lock_no'])->toBe('1.1.101')
        ->and($job->payload['check_out'])->toBe('2026-10-16 12:00:00')
        ->and($job->payload['expected_serial'])->toBeNull()
        ->and($job->payload['display']['room'])->toBe('101')
        ->and($job->expires_at->toIso8601String())->toBe(now()->addMinutes(10)->toIso8601String());
});

it('refuses a TTLock lock, a second open job, and a departure that has passed', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();
    $ttlock = DoorLock::factory()->for($property)->create();

    expect(app(CreateKeyportJob::class)($owner, $unit, $ttlock, KeyportJobType::Issue))->toBeString();

    app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue);
    expect(app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue))->toBeString()
        ->and(KeyportJob::query()->count())->toBe(1);

    KeyportJob::query()->update(['status' => KeyportJobStatus::Cancelled->value]);
    $this->travelTo(now()->addDays(3));
    expect(app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue))->toBeString();
});

it('rewrites and erases only the card this stay made, naming its serial', function () {
    ['owner' => $owner, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();

    expect(app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Erase))->toBeString();

    $issued = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue);
    $issued->forceFill(['status' => KeyportJobStatus::Succeeded, 'serial' => 'A1B2C3D4', 'finished_at' => now()])->save();

    $erase = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Erase);

    expect($erase->payload['expected_serial'])->toBe('A1B2C3D4')->and($erase->payload['check_out'])->toBeNull();
});

it('returns the same job for a repeated key', function () {
    ['owner' => $owner, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();

    $first = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue, 'form-1');
    KeyportJob::query()->update(['status' => KeyportJobStatus::Cancelled->value]);
    $second = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue, 'form-1');

    expect($second->id)->toBe($first->id)->and(KeyportJob::query()->count())->toBe(1);
});

it('queues one key card job at check-in, only with a paired PC, the feature on and an eLock lock', function () {
    ['property' => $property, 'unit' => $unit] = keyportElockSetup();

    StayCheckedIn::dispatch($property->id, $unit->id);
    StayCheckedIn::dispatch($property->id, $unit->id);
    expect(KeyportJob::query()->count())->toBe(1)->and(KeyportJob::query()->sole()->issued_by)->toBeNull();

    KeyportJob::query()->delete();
    Feature::for($property)->deactivate(DoorLocks::class);
    StayCheckedIn::dispatch($property->id, $unit->id);
    expect(KeyportJob::query()->count())->toBe(0);
});

it('queues nothing at check-in without a Keyport PC or for a TTLock room', function () {
    ['property' => $property, 'unit' => $unit, 'device' => $device] = keyportElockSetup();
    $device->forceFill(['revoked_at' => now()])->save();

    StayCheckedIn::dispatch($property->id, $unit->id);
    expect(KeyportJob::query()->count())->toBe(0);

    ['property' => $other, 'room' => $room] = doorLockSetup();
    KeyportDevice::factory()->for($other)->create();
    StayCheckedIn::dispatch($other->id, doorLockStay($other, $room)->id);
    expect(KeyportJob::query()->count())->toBe(0);
});

it('cancels a pending job once and refuses after the PC claimed it', function () {
    ['owner' => $owner, 'lock' => $lock, 'unit' => $unit, 'device' => $device] = keyportElockSetup();
    $job = app(CreateKeyportJob::class)($owner, $unit, $lock, KeyportJobType::Issue);

    expect(app(CancelKeyportJob::class)($owner, $job))->toBeTrue()
        ->and($job->refresh()->status)->toBe(KeyportJobStatus::Cancelled)
        ->and(app(CancelKeyportJob::class)($owner, $job))->toBeFalse();

    $claimed = KeyportJob::factory()->claimedBy($device)->create();
    expect(app(CancelKeyportJob::class)($owner, $claimed))->toBeFalse();
});

it('refuses a lock outside the stay room, a guest who is not checked in, and a key from another stay', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'unit' => $unit] = keyportElockSetup();
    $elsewhere = DoorLock::factory()->elock('1.1.202')->for($property)->create(['room_id' => Room::factory()->create(['property_id' => $property->id])->id]);

    expect(app(CreateKeyportJob::class)($owner, $unit, $elsewhere, KeyportJobType::Issue))->toBeString();

    $unit->forceFill(['status' => 'confirmed'])->save();
    expect(app(CreateKeyportJob::class)($owner, $unit->refresh(), $lock, KeyportJobType::Issue))->toBeString()
        ->and(KeyportJob::query()->count())->toBe(0);

    $unit->forceFill(['status' => 'checked_in'])->save();
    app(CreateKeyportJob::class)($owner, $unit->refresh(), $lock, KeyportJobType::Issue, 'checkin:1:1');
    $other = keyportElockSetup();

    expect(app(CreateKeyportJob::class)($other['owner'], $other['unit'], $other['lock'], KeyportJobType::Issue, 'checkin:1:1'))->toBeString();
});
