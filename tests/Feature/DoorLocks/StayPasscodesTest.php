<?php

use App\Actions\DoorLocks\IssueDoorPasscode;
use App\Actions\DoorLocks\SyncStayPasscodes;
use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Events\StayCheckedIn;
use App\Events\StayCheckedOut;
use App\Events\StayDatesChanged;
use App\Jobs\ExpireDoorCredentials;
use App\Jobs\SyncStayPasscodesJob;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\Room;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
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

    StayDatesChanged::dispatch($property->id, $unit->id);
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

it('issues a new PIN when the stay is extended and leaves the old one to run out', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);
    $unit->update(['departure_date' => '2026-10-18']);

    app(SyncStayPasscodes::class)($unit->refresh());
    app(SyncStayPasscodes::class)($unit->refresh());

    $passcodes = DoorPasscode::query()->orderBy('id')->get();
    expect($passcodes)->toHaveCount(2)
        ->and($passcodes->pluck('status')->all())->toBe([PasscodeStatus::Active, PasscodeStatus::Active])
        ->and($passcodes[0]->idempotency_key)->toBe("stay:{$unit->id}:{$passcodes[0]->door_lock_id}")
        ->and($passcodes[1]->idempotency_key)->toBe("stay:{$unit->id}:{$passcodes[0]->door_lock_id}:".$passcodes[1]->ends_at->getTimestamp())
        ->and($passcodes[1]->ends_at->gt($passcodes[0]->ends_at))->toBeTrue()
        ->and(ttlockCalls('/v3/keyboardPwd/get'))->toBe(2)
        ->and(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(0);
});

it('keeps the PIN when the stay is shortened', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);
    $unit->update(['departure_date' => '2026-10-15']);

    app(SyncStayPasscodes::class)($unit->refresh());

    expect(DoorPasscode::query()->count())->toBe(1);
});

it('does not drop an event that arrives while the sync is running', function () {
    expect(SyncStayPasscodesJob::class)->toImplement(ShouldBeUniqueUntilProcessing::class);
});

it('never runs two syncs of the same unit together', function () {
    $middleware = (new SyncStayPasscodesJob(7))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('7');
});

it('saves the Delete PIN note when the check-out revokes a PIN on a lock without a gateway', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup(gateway: false);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);

    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    app(SyncStayPasscodes::class)($unit->refresh());

    expect(DoorPasscode::query()->sole()->revoke_note)->toContain('482913');
});

it('clears the revoke note when the same stay gets its PIN again', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup(gateway: false);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    app(SyncStayPasscodes::class)($unit->refresh());
    $unit->forceFill(['status' => UnitStatus::CheckedIn, 'checked_out_at' => null])->save();

    app(SyncStayPasscodes::class)($unit->refresh());

    $passcode = DoorPasscode::query()->sole();
    expect($passcode->status)->toBe(PasscodeStatus::Active)
        ->and($passcode->revoke_note)->toBeNull()
        ->and($passcode->revoked_at)->toBeNull();
});

it('marks active PINs and cards past their end as expired and leaves the rest', function () {
    ['lock' => $lock] = doorLockSetup();
    $past = DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()]);
    $live = DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'ends_at' => now()->addHour()]);
    $revoked = DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute(), 'status' => PasscodeStatus::Revoked]);
    $card = DoorCard::factory()->create(['door_lock_id' => $lock->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()]);

    app()->call([new ExpireDoorCredentials, 'handle']);

    expect($past->refresh()->status)->toBe(PasscodeStatus::Expired)
        ->and($live->refresh()->status)->toBe(PasscodeStatus::Active)
        ->and($revoked->refresh()->status)->toBe(PasscodeStatus::Revoked)
        ->and($card->refresh()->status)->toBe(PasscodeStatus::Expired);
});

it('makes a random PIN of the configured length', function () {
    ['property' => $property, 'room' => $room, 'connection' => $connection] = doorLockSetup(gateway: true);
    $connection->update(['passcode_mode' => 'custom', 'passcode_length' => 8]);
    fakeTtlock();

    app(SyncStayPasscodes::class)(doorLockStay($property, $room));

    expect(DoorPasscode::query()->sole()->code)->toMatch('/^[1-9]\d{7}$/');
});

it('makes a random PIN again once when TTLock says it already exists', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    Http::fake([
        '*/v3/keyboardPwd/add' => Http::sequence()
            ->push(['errcode' => -3007, 'errmsg' => 'exists'])
            ->push(['keyboardPwdId' => 9002]),
    ]);

    $result = app(IssueDoorPasscode::class)($lock, PasscodeKind::Custom, 'Stay', now(), now()->addDay(), 'k1');

    expect($result->passcode->status)->toBe(PasscodeStatus::Active)
        ->and(ttlockCalls('/v3/keyboardPwd/add'))->toBe(2);
});

it('keeps a PIN staff typed and says it is already used', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    Http::fake(['*/v3/keyboardPwd/add' => Http::response(['errcode' => -3007, 'errmsg' => 'exists'])]);

    $result = app(IssueDoorPasscode::class)($lock, PasscodeKind::Custom, 'Stay', now(), now()->addDay(), 'k2', '123456');

    expect($result->passcode->status)->toBe(PasscodeStatus::Failed)
        ->and($result->passcode->error)->toContain('already used')
        ->and(ttlockCalls('/v3/keyboardPwd/add'))->toBe(1);
});

function replaceUrl($unit, $passcode): string
{
    return route('reservations.units.door-pins.replace', [
        'property' => $unit->property,
        'reservation' => $unit->reservation_id,
        'unit' => $unit->id,
        'doorPasscode' => $passcode->id,
    ]);
}

it('lets staff replace the stay PIN with one they typed', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);
    $old = DoorPasscode::query()->sole();

    $this->actingAs($owner)->post(replaceUrl($unit, $old), ['code' => '246810'])->assertRedirect();

    expect($old->refresh()->status)->toBe(PasscodeStatus::Revoked)
        ->and(DoorPasscode::query()->where('status', PasscodeStatus::Active)->sole()->code)->toBe('246810');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/keyboardPwd/add') && $request['keyboardPwd'] === '246810');
});

it('keeps the current PIN when the typed one is refused', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);
    $old = DoorPasscode::query()->sole();
    Http::swap(new Factory);
    Http::fake(['*/v3/keyboardPwd/add' => Http::response(['errcode' => -3007, 'errmsg' => 'exists'])]);

    $this->actingAs($owner)->post(replaceUrl($unit, $old), ['code' => '246810'])->assertRedirect();

    expect($old->refresh()->status)->toBe(PasscodeStatus::Active)
        ->and(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(0);
});

it('refuses a typed PIN that is not 4 to 9 digits', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    app(SyncStayPasscodes::class)($unit);

    $this->actingAs($owner)->post(replaceUrl($unit, DoorPasscode::query()->sole()), ['code' => '12'])->assertSessionHasErrors('code');
});

it('deletes an expired PIN and card from a lock with a gateway, and expires them even when TTLock refuses', function () {
    ['lock' => $lock] = doorLockSetup(gateway: true);
    $lock->update(['ttlock_lock_id' => 555]);
    $passcode = DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()]);
    $card = DoorCard::factory()->create(['door_lock_id' => $lock->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()]);
    Http::fake(['*/v3/keyboardPwd/delete' => Http::response(['errcode' => 0]), '*/v3/identityCard/delete' => Http::response(['errcode' => -1, 'errmsg' => 'no'])]);

    app()->call([new ExpireDoorCredentials, 'handle']);

    expect(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(1)
        ->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1)
        ->and($passcode->refresh()->status)->toBe(PasscodeStatus::Expired)
        ->and($card->refresh()->status)->toBe(PasscodeStatus::Expired);
});

it('does not call TTLock for an expired PIN on a lock without a gateway', function () {
    ['lock' => $lock] = doorLockSetup(gateway: false);
    DoorPasscode::factory()->create(['door_lock_id' => $lock->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->subMinute()]);
    Http::fake();

    app()->call([new ExpireDoorCredentials, 'handle']);

    expect(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(0);
});

it('answers 404 to the staff PIN route while Door locks is off', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true, feature: false);
    $unit = doorLockStay($property, $room);
    $passcode = DoorPasscode::factory()->create(['door_lock_id' => DoorLock::query()->where('property_id', $property->id)->value('id'), 'reservation_unit_id' => $unit->id]);

    $this->actingAs($owner)->post(replaceUrl($unit, $passcode), ['code' => '246810'])->assertNotFound();
});
