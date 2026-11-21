<?php

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\DoorLock;
use App\Models\DoorPasscode;
use App\Models\Guest;
use App\Models\Room;
use App\Models\UnitGuest;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/DoorLockSetup.php';

function logStayCheckIn($unit, string $event = 'concierge.checked_in'): void
{
    activity()->performedOn($unit)->event($event)->withProperties(['property_id' => $unit->property_id])->log('Checked in');
}

it('offers the stay the concierge just checked in, until it has a card', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    logStayCheckIn($unit);
    fakeTtlock();

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertInertia(fn (Assert $page) => $page->where('waitingStay.unitId', $unit->id)->where('cards.meta.total', 0));

    $this->actingAs($owner)->post(route('settings.door-locks.cards.store', $property), ['uid' => '0DD25CE7', 'reservation_unit_id' => $unit->id])
        ->assertRedirect()->assertInertiaFlash('toast.type', 'success');

    $card = DoorCard::query()->sole();
    expect($card->status)->toBe(PasscodeStatus::Active)->and($card->reservation_unit_id)->toBe($unit->id);

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertInertia(fn (Assert $page) => $page->where('waitingStay', null)->where('cards.meta.total', 1)->where('cards.data.0.cardNumber', '231890151'));
});

it('answers the desk PC watcher with the waiting stay as JSON, and adds the card back on the page it came from', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    $this->actingAs($owner)->getJson(route('settings.door-locks.waiting', $property))->assertOk()->assertJsonPath('waitingStay', null);

    logStayCheckIn($unit);
    fakeTtlock();
    $this->actingAs($owner)->getJson(route('settings.door-locks.waiting', $property))->assertOk()->assertJsonPath('waitingStay.unitId', $unit->id);

    $this->actingAs($owner)->from('/somewhere')->post(route('settings.door-locks.cards.store', $property), ['uid' => '0DD25CE7', 'reservation_unit_id' => $unit->id])
        ->assertRedirect('/somewhere');
});

it('shows the primary guest, booking reference and room of a card and a PIN', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    $guest = UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id]);
    UnitGuest::factory()->create(['reservation_unit_id' => $unit->id]);
    fakeTtlock();
    $this->actingAs($owner)->post(route('settings.door-locks.cards.store', $property), ['uid' => '0DD25CE7', 'reservation_unit_id' => $unit->id]);
    DoorPasscode::factory()->create(['property_id' => $property->id, 'door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id]);
    DoorPasscode::factory()->create(['property_id' => $property->id, 'door_lock_id' => $lock->id, 'reservation_unit_id' => null]);

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.data.0.guestName', $guest->guest->full_name)
            ->where('cards.data.0.reference', $unit->reservation->reference)
            ->where('cards.data.0.room', '101')
            ->where('passcodes.data.1.guestName', $guest->guest->full_name)
            ->where('passcodes.data.1.room', '101')
            ->where('passcodes.data.0.guestName', null)
            ->where('passcodes.data.0.reference', null));
});

it('falls back to the booker when the stay has no primary guest', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room, 'lock' => $lock] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    $booker = Guest::factory()->create(['property_id' => $property->id]);
    $unit->reservation->update(['booker_guest_id' => $booker->id]);
    DoorPasscode::factory()->create(['property_id' => $property->id, 'door_lock_id' => $lock->id, 'reservation_unit_id' => $unit->id]);

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('passcodes.data.0.guestName', $booker->full_name)
            ->where('passcodes.data.0.reference', $unit->reservation->reference));
});

it('offers no stay that is not a recent concierge check-in on a lock with a gateway', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $second = Room::factory()->create(['property_id' => $property->id, 'number' => '102']);
    DoorLock::factory()->for($property)->create(['room_id' => $second->id, 'has_gateway' => true]);
    logStayCheckIn(doorLockStay($property, $room), 'kiosk.checked_in');
    logStayCheckIn(doorLockStay($property, $second));
    $this->travel(31)->minutes();

    $this->actingAs($owner)->get(route('settings.door-locks.edit', $property))
        ->assertInertia(fn (Assert $page) => $page->where('waitingStay', null));

    $this->travelBack();
    ['owner' => $other, 'property' => $noGateway, 'room' => $noGatewayRoom] = doorLockSetup();
    logStayCheckIn(doorLockStay($noGateway, $noGatewayRoom));

    $this->actingAs($other)->get(route('settings.door-locks.edit', $noGateway))
        ->assertInertia(fn (Assert $page) => $page->where('waitingStay', null));
});

it('registers a card only for a stay of this property', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup(gateway: true);
    ['property' => $otherProperty, 'room' => $otherRoom] = doorLockSetup(gateway: true);
    $foreign = doorLockStay($otherProperty, $otherRoom);
    fakeTtlock();

    $this->actingAs($owner)->post(route('settings.door-locks.cards.store', $property), ['uid' => '0DD25CE7', 'reservation_unit_id' => $foreign->id])
        ->assertNotFound();

    expect(DoorCard::query()->exists())->toBeFalse();
});

it('removes a card from the list', function () {
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup(gateway: true);
    $unit = doorLockStay($property, $room);
    fakeTtlock();
    $this->actingAs($owner)->post(route('settings.door-locks.cards.store', $property), ['uid' => '0DD25CE7', 'reservation_unit_id' => $unit->id]);
    $card = DoorCard::query()->sole();

    $this->actingAs($owner)->delete(route('settings.door-locks.cards.destroy', [$property, $card]))
        ->assertRedirect()->assertInertiaFlash('toast.type', 'success');

    expect($card->fresh()->status)->toBe(PasscodeStatus::Revoked);
});

it('keeps another property card out of reach', function () {
    ['owner' => $owner, 'property' => $property] = doorLockSetup(gateway: true);
    ['property' => $otherProperty, 'room' => $otherRoom] = doorLockSetup(gateway: true);
    $foreign = DoorCard::factory()->create([
        'property_id' => $otherProperty->id,
        'door_lock_id' => $otherProperty->doorLocks()->first()->id,
        'reservation_unit_id' => doorLockStay($otherProperty, $otherRoom)->id,
    ]);

    $this->actingAs($owner)->delete(route('settings.door-locks.cards.destroy', [$property, $foreign]))->assertNotFound();
});
