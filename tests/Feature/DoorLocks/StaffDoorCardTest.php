<?php

use App\Actions\DoorLocks\RemoveStayCards;
use App\Enums\PasscodeStatus;
use App\Enums\PermissionEnum;
use App\Models\DoorCard;
use App\Models\Room;
use App\Models\User;

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

    app(RemoveStayCards::class)($unit);

    expect(DoorCard::query()->sole()->status)->toBe(PasscodeStatus::Revoked)->and(ttlockCalls('/v3/identityCard/delete'))->toBe(1);
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
