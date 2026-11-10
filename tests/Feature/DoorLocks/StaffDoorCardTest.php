<?php

use App\Actions\DoorLocks\RemoveDoorCard;
use App\Actions\DoorLocks\SyncStayCards;
use App\Enums\PasscodeStatus;
use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Events\StayCheckedOut;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\Room;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../Support/DoorLockSetup.php';

function doorCardUrl($unit): string
{
    return route('reservations.units.door-cards.store', [
        'property' => $unit->property,
        'reservation' => $unit->reservation_id,
        'unit' => $unit->id,
    ]);
}

it('lets staff register the card they scanned with the stay lock', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7'])->assertRedirect();

    $card = DoorCard::query()->sole();
    expect($card->status)->toBe(PasscodeStatus::Active)
        ->and($card->card_number)->toBe(231890151)
        ->and($card->reservation_unit_id)->toBe($unit->id)
        ->and(ttlockCalls('/v3/identityCard/add'))->toBe(1);
});

it('refuses a lock without a gateway and saves no card', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7'])->assertRedirect();

    expect(DoorCard::query()->exists())->toBeFalse()->and(ttlockCalls('/v3/identityCard/add'))->toBe(0);
});

it('refuses a UID that is not a card number', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();

    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => 'not-a-card'])->assertRedirect();

    expect(DoorCard::query()->exists())->toBeFalse();
});

it('lists the stay cards on the reservation page and removes one from the lock', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7']);
    $card = DoorCard::query()->sole();

    $this->actingAs($owner)->get(route('reservations.show', ['property' => $property, 'reservation' => $unit->reservation_id, 'unit' => $unit->id]))
        ->assertInertia(fn ($page) => $page->where('doorPins.cards.0.cardNumber', '231890151')->where('doorPins.cards.0.canRemove', true));

    $this->actingAs($owner)->delete(route('reservations.units.door-cards.destroy', ['property' => $property, 'reservation' => $unit->reservation_id, 'unit' => $unit->id, 'doorCard' => $card->id]))->assertRedirect();

    expect($card->refresh()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1);
});

it('removes the stay cards from the lock when the stay is checked out', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7']);

    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    app(SyncStayCards::class)($unit->refresh());

    expect(DoorCard::query()->sole()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1);
});

it('leaves the cards to the kiosk while the kiosk holds the stay', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7']);
    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();

    Cache::lock('kiosk-register-card:'.$unit->id, 120)->get();
    app(SyncStayCards::class)($unit->refresh());

    expect(DoorCard::query()->sole()->status)->toBe(PasscodeStatus::Active)->and(ttlockCalls('/v3/identityCard/delete'))->toBe(0);
});

it('adds the card to the new room after a room move and does not put back a card staff removed', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7']);
    $newRoom = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);
    $newLock = DoorLock::factory()->for($property)->create(['room_id' => $newRoom->id, 'has_gateway' => true, 'ttlock_lock_id' => 556]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '2026-10-15')->update(['room_id' => $newRoom->id]);

    app(SyncStayCards::class)($unit->refresh());

    expect(DoorCard::query()->where('door_lock_id', $newLock->id)->sole()->status)->toBe(PasscodeStatus::Active)->and(ttlockCalls('/v3/identityCard/add'))->toBe(2);

    app(RemoveDoorCard::class)(DoorCard::query()->where('door_lock_id', $newLock->id)->sole()->load('doorLock.property.doorLockConnection'));
    app(SyncStayCards::class)($unit->refresh());

    expect(DoorCard::query()->where('status', PasscodeStatus::Active)->count())->toBe(1)->and(ttlockCalls('/v3/identityCard/add'))->toBe(2);
});

it('registers the card again with the new end after a date change', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7']);
    $oldEnd = DoorCard::query()->sole()->ends_at;

    $unit->update(['departure_date' => '2026-10-18']);
    app(SyncStayCards::class)($unit->refresh());

    $live = DoorCard::query()->where('status', PasscodeStatus::Active)->sole();
    expect($live->ends_at->gt($oldEnd))->toBeTrue()
        ->and(DoorCard::query()->where('status', PasscodeStatus::Revoked)->count())->toBe(1)
        ->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1)
        ->and(ttlockCalls('/v3/identityCard/add'))->toBe(2);
});

it('still removes the cards at check-out when auto-issuing PINs was switched off', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'connection' => $connection] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7']);
    $connection->update(['enabled' => false]);

    $unit->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    StayCheckedOut::dispatch($property->id, $unit->id);

    expect(DoorCard::query()->sole()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1);
});

it('does not add a fifth card to a lock', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    DoorCard::factory()->count(4)->create(['door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id, 'status' => PasscodeStatus::Active, 'ends_at' => now()->addDay()]);

    $this->actingAs($owner)->post(doorCardUrl($unit), ['uid' => '0DD25CE7'])->assertRedirect();

    expect(DoorCard::query()->count())->toBe(4)->and(ttlockCalls('/v3/identityCard/add'))->toBe(0);
});

it('does not remove a card of another stay', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    $other = doorLockStay($property, Room::factory()->create(['property_id' => $property->id, 'number' => '102']));
    $card = DoorCard::factory()->create(['door_lock_id' => $property->doorLocks()->first()->id, 'reservation_unit_id' => $other->id]);

    $this->actingAs($owner)->delete(route('reservations.units.door-cards.destroy', ['property' => $property, 'reservation' => $unit->reservation_id, 'unit' => $unit->id, 'doorCard' => $card->id]))->assertNotFound();
});

it('needs the check-in permission', function () {
    ['property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($viewer)->post(doorCardUrl($unit), ['uid' => '0DD25CE7'])->assertForbidden();
});
