<?php

use App\Enums\PasscodeKind;
use App\Enums\PasscodeStatus;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/DoorLockSetup.php';

it('answers 404 while the Door locks feature is off', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup(feature: false);

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))->assertNotFound();
});

it('shows the page without any token', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup();

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/door-locks/index')
            ->where('connection.signedIn', true)
            ->missing('connection.accessToken')
            ->missing('connection.refreshToken')
            ->has('locks', 1));
});

it('signs in once and never saves the password', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup();
    $property->doorLockConnection->delete();
    fakeTtlock();

    $this->actingAs($owner)->put(route('settings.door-locks.update', $property), [
        'ttlock_username' => 'owner@example.com',
        'ttlock_password' => 'secret-pass',
        'passcode_mode' => 'generated',
        'enabled' => true,
    ])->assertRedirect(route('settings.door-locks.edit', $property));

    $connection = $property->refresh()->doorLockConnection;
    expect($connection->access_token)->toBe('at-new')
        ->and($connection->refresh_token)->toBe('rt-new')
        ->and(json_encode($connection->getAttributes()))->not->toContain('secret-pass');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth2/token') && $request['password'] === md5('secret-pass'));
    expect(Activity::query()->where('event', 'door-locks.connection-saved')->exists())->toBeTrue();
});

it('needs the password for a first sign in', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup();
    $property->doorLockConnection->delete();

    $this->actingAs($owner)->put(route('settings.door-locks.update', $property), [
        'ttlock_username' => 'owner@example.com',
        'passcode_mode' => 'generated',
    ])->assertSessionHasErrors('ttlock_password');
});

it('keeps the room link when it syncs the lock list', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock, 'room' => $room] = doorLockSetup();
    $lock->update(['ttlock_lock_id' => 555, 'has_gateway' => false]);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.sync', $property))->assertRedirect();

    expect($lock->refresh()->room_id)->toBe($room->id)
        ->and($lock->has_gateway)->toBeTrue()
        ->and($lock->name)->toBe('Room 101 lock')
        ->and(DoorLock::query()->where('property_id', $property->id)->count())->toBe(1);
});

it('links a lock to a room of the same property only', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup();
    $other = Room::factory()->create();
    $free = Room::factory()->create(['property_id' => $property->id]);

    $this->actingAs($owner)->put(route('settings.door-locks.link', [$property, $lock]), ['room_id' => $other->id])->assertRedirect();
    expect($lock->refresh()->room_id)->not->toBe($other->id);

    $this->actingAs($owner)->put(route('settings.door-locks.link', [$property, $lock]), ['room_id' => $free->id])->assertRedirect();
    expect($lock->refresh()->room_id)->toBe($free->id);
});

it('issues a generated PIN without a gateway', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup();
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.store', $property), [
        'door_lock_id' => $lock->id,
        'kind' => 'generated',
        'label' => 'Cleaner',
        'ends_at' => '2026-10-15T18:00',
    ])->assertRedirect();

    $passcode = DoorPasscode::query()->sole();
    expect($passcode->status)->toBe(PasscodeStatus::Active)
        ->and($passcode->code)->toBe('482913')
        ->and($passcode->kind)->toBe(PasscodeKind::Generated);
    expect(ttlockCalls('/v3/keyboardPwd/get'))->toBe(1);
});

it('refuses a custom PIN without a gateway and writes nothing', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: false);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.store', $property), [
        'door_lock_id' => $lock->id,
        'kind' => 'custom',
        'label' => 'Contractor',
        'code' => '123456',
        'ends_at' => '2026-10-15T18:00',
    ])->assertRedirect()->assertInertiaFlash('toast.type', 'error');

    expect(DoorPasscode::query()->count())->toBe(0)
        ->and(ttlockCalls('/v3/keyboardPwd/add'))->toBe(0);
});

it('sends a custom PIN through a gateway', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.store', $property), [
        'door_lock_id' => $lock->id,
        'kind' => 'custom',
        'label' => 'Contractor',
        'code' => '123456',
        'ends_at' => '2026-10-15T18:00',
    ])->assertRedirect();

    $passcode = DoorPasscode::query()->sole();
    expect($passcode->status)->toBe(PasscodeStatus::Active)
        ->and($passcode->code)->toBe('123456')
        ->and($passcode->ttlock_passcode_id)->toBe(9002);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/keyboardPwd/add') && $request['addType'] === 2 && $request['keyboardPwd'] === '123456');
});

it('rejects a PIN that is not 4 to 9 digits', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.store', $property), [
        'door_lock_id' => $lock->id,
        'kind' => 'custom',
        'label' => 'x',
        'code' => '12',
        'ends_at' => '2026-10-15T18:00',
    ])->assertSessionHasErrors('code');
});

it('saves a TTLock refusal on the PIN as Failed and retries it', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup();
    Http::fake(['*/v3/keyboardPwd/get' => Http::sequence()
        ->push(['errcode' => -3003, 'errmsg' => 'lock busy'])
        ->push(['keyboardPwd' => '777888', 'keyboardPwdId' => 9100])]);

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.store', $property), [
        'door_lock_id' => $lock->id, 'kind' => 'generated', 'label' => 'Guest', 'ends_at' => '2026-10-15T18:00',
    ])->assertRedirect();

    $passcode = DoorPasscode::query()->sole();
    expect($passcode->status)->toBe(PasscodeStatus::Failed)->and($passcode->error)->toContain('lock busy');

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.retry', [$property, $passcode]))->assertRedirect();

    expect($passcode->refresh()->status)->toBe(PasscodeStatus::Active)->and($passcode->code)->toBe('777888')
        ->and(DoorPasscode::query()->count())->toBe(1);
});

it('refuses remote unlock without a gateway and calls nothing', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: false);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.unlock', [$property, $lock]))
        ->assertRedirect()->assertInertiaFlash('toast.type', 'error');

    expect(ttlockCalls('/v3/lock/unlock'))->toBe(0);
});

it('unlocks through a gateway', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: true);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.unlock', [$property, $lock]))->assertRedirect();

    expect(ttlockCalls('/v3/lock/unlock'))->toBe(1);
});

it('revokes through a gateway by deleting the PIN from the lock', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: true);
    $passcode = DoorPasscode::factory()->create(['door_lock_id' => $lock->id]);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.revoke', [$property, $passcode]))->assertRedirect();

    expect($passcode->refresh()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(1);
});

it('adjusts the lock clock through a gateway and refuses without one', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: false);
    fakeTtlock();
    Http::fake(['*/v3/lock/updateDate' => Http::response(['date' => 1626945087000])]);

    $this->actingAs($owner)->post(route('settings.door-locks.clock', [$property, $lock]))->assertRedirect()->assertInertiaFlash('toast.type', 'error');
    expect(ttlockCalls('/v3/lock/updateDate'))->toBe(0);

    $lock->update(['has_gateway' => true]);
    $this->actingAs($owner)->post(route('settings.door-locks.clock', [$property, $lock]))->assertRedirect()->assertInertiaFlash('toast.type', 'success');
    expect(ttlockCalls('/v3/lock/updateDate'))->toBe(1);
});

it('gives staff a Delete PIN to type on the keypad when it revokes without a gateway', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: false);
    $passcode = DoorPasscode::factory()->create(['door_lock_id' => $lock->id]);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.revoke', [$property, $passcode]))
        ->assertRedirect()->assertInertiaFlash('toast.type', 'warning');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/keyboardPwd/get') && $request['keyboardPwdType'] === 4);
    expect(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(0);
});

it('revokes without a gateway with a warning that the lock still accepts the PIN', function () {
    ['owner' => $owner, 'property' => $property, 'lock' => $lock] = doorLockSetup(gateway: false);
    $passcode = DoorPasscode::factory()->create(['door_lock_id' => $lock->id]);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.revoke', [$property, $passcode]))
        ->assertRedirect()->assertInertiaFlash('toast.type', 'warning');

    expect($passcode->refresh()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('/v3/keyboardPwd/delete'))->toBe(0);
});

it('answers 404 for another property’s lock and PIN', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup();
    $foreign = DoorLock::factory()->create();
    $foreignPasscode = DoorPasscode::factory()->create(['door_lock_id' => $foreign->id]);

    $this->actingAs($owner)->post(route('settings.door-locks.unlock', [$property, $foreign]))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.door-locks.passcodes.revoke', [$property, $foreignPasscode]))->assertNotFound();
});

it('keeps staff without settings.manage out', function () {
    ['property' => $property] = doorLockSetup();
    $staff = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($staff)->get(route('settings.door-locks.edit', $property))->assertForbidden();
});
