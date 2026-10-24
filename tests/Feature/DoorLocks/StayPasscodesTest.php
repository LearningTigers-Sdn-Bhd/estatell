<?php

use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Events\StayCheckedIn;
use App\Events\StayCheckedOut;
use App\Jobs\SyncStayPasscodesJob;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\Room;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/DoorLockSetup.php';

it('issues one PIN for a checked-in stay, however many times it runs', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit);
    app(SyncStayPasscodes::class)($unit);

    $passcode = DoorPasscode::query()->sole();
    expect($passcode->status)->toBe(PasscodeStatus::Active)
        ->and($passcode->reservation_unit_id)->toBe($unit->id)
        ->and($passcode->idempotency_key)->toBe("stay:{$unit->id}:{$passcode->door_lock_id}")
        ->and(ttlockCalls('/v3/keyboardPwd/get'))->toBe(1);
});

it('ends the PIN at the unit departure, rounded up to the hour', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    $unit->update(['departure_time' => '11:30:00']);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit->refresh());

    // 2026-10-16 11:30 Kuala Lumpur is 03:30 UTC, rounded up to 04:00 UTC.
    expect(DoorPasscode::query()->sole()->ends_at->toIso8601String())->toBe('2026-10-16T04:00:00+00:00');
});

it('uses a generated PIN on a lock without a gateway even in custom mode', function () {
    ['property' => $property, 'room' => $room, 'connection' => $connection] = doorLockSetup(gateway: false);
    $connection->update(['passcode_mode' => 'custom']);
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit);

    expect(ttlockCalls('/v3/keyboardPwd/get'))->toBe(1)->and(ttlockCalls('/v3/keyboardPwd/add'))->toBe(0);
});

it('sends a custom PIN on a lock with a gateway in custom mode', function () {
    ['property' => $property, 'room' => $room, 'connection' => $connection] = doorLockSetup(gateway: true);
    $connection->update(['passcode_mode' => 'custom']);
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit);

    expect(ttlockCalls('/v3/keyboardPwd/add'))->toBe(1);
});

it('skips a room with no lock and a switched-off connection', function () {
    ['property' => $property, 'room' => $room, 'lock' => $lock, 'connection' => $connection] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    $lock->update(['room_id' => null]);
    app(SyncStayPasscodes::class)($unit);
    expect(DoorPasscode::query()->count())->toBe(0);

    $lock->update(['room_id' => $room->id]);
    $connection->update(['enabled' => false]);
    app(SyncStayPasscodes::class)($unit->refresh());
    expect(DoorPasscode::query()->count())->toBe(0)->and(ttlockCalls('/v3/keyboardPwd/get'))->toBe(0);
});

it('revokes the PIN when the stay is no longer checked in', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit);
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    app(SyncStayPasscodes::class)($unit->refresh());

    expect(DoorPasscode::query()->sole()->status)->toBe(PasscodeStatus::Revoked);
});

it('moves the PIN to the new room’s lock', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $other = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);
    $newLock = DoorLock::factory()->for($property)->create(['room_id' => $other->id]);
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    app(SyncStayPasscodes::class)($unit);
    $unit->nights()->update(['room_id' => $other->id]);
    app(SyncStayPasscodes::class)($unit->refresh());

    expect(DoorPasscode::query()->where('door_lock_id', $newLock->id)->sole()->status)->toBe(PasscodeStatus::Active)
        ->and(DoorPasscode::query()->where('status', PasscodeStatus::Revoked)->count())->toBe(1);
});

it('queues a sync from the stay events only when the feature and the connection are on', function () {
    ['property' => $property, 'room' => $room, 'connection' => $connection] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    Queue::fake();

    StayCheckedIn::dispatch($property->id, $unit->id);
    StayCheckedOut::dispatch($property->id, $unit->id);
    Queue::assertPushed(SyncStayPasscodesJob::class, 1); // unique by unit: the second event joins the first

    $connection->update(['enabled' => false]);
    StayCheckedIn::dispatch($property->id, $unit->id + 1);
    Queue::assertPushed(SyncStayPasscodesJob::class, 1);
});

it('keeps a failed TTLock call off the stay: the PIN is saved as Failed', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    Http::fake(['*/v3/keyboardPwd/get' => Http::response(['errcode' => -2012, 'errmsg' => 'lock offline'])]);

    app(SyncStayPasscodes::class)($unit);

    expect(DoorPasscode::query()->sole()->status)->toBe(PasscodeStatus::Failed);
});
