<?php

use App\Actions\Reservations\AssignBed;
use App\Actions\Reservations\AssignRoom;
use App\Enums\HousekeepingStatus;
use App\Enums\UnitStatus;
use App\Models\RoomStatus;
use App\Models\User;
use App\Services\Availability\FreeUnits;

/**
 * A unit checked in on the nights before the walk-in's arrival, leaving on the arrival date and not yet checked out.
 */
function checkedInLeavingToday(array $setup, object $roomType, object $unit): object
{
    return assignUnit($setup['property'], $roomType, [
        'status' => UnitStatus::CheckedIn,
        'checked_in_at' => now(),
        'arrival_date' => '2026-10-18',
        'departure_date' => '2026-10-20',
    ]);
}

function markDirty(object $room): void
{
    RoomStatus::query()->updateOrCreate(['room_id' => $room->id], ['property_id' => $room->property_id, 'status' => HousekeepingStatus::Dirty]);
}

function walkInFree(object $roomType): array
{
    return array_map(fn ($unit) => [$unit->label, $unit->reason], app(FreeUnits::class)->forStay($roomType, now()->setDate(2026, 10, 20), now()->setDate(2026, 10, 22), includeNotReady: true));
}

test('a walk-in lists a room still held by a checked-in unit as In house', function () {
    $setup = assignSetup();
    app(AssignRoom::class)($setup['user'], checkedInLeavingToday($setup, $setup['roomType'], $setup['unit']), $setup['rooms'][0]);

    expect(walkInFree($setup['roomType']))->toBe([['101', 'In house'], ['102', null]])
        ->and(array_map(fn ($unit) => $unit->label, app(FreeUnits::class)->forStay($setup['roomType'], now()->setDate(2026, 10, 20), now()->setDate(2026, 10, 22))))->toBe(['101', '102']);
});

test('a walk-in lists a room in a Dirty room state as Dirty, and a new booking still lists it', function () {
    $setup = assignSetup();
    markDirty($setup['rooms'][0]);

    expect(walkInFree($setup['roomType']))->toBe([['101', 'Dirty'], ['102', null]])
        ->and(array_map(fn ($unit) => $unit->label, app(FreeUnits::class)->forStay($setup['roomType'], now()->setDate(2026, 10, 20), now()->setDate(2026, 10, 22))))->toBe(['101', '102']);
});

test('a bed takes Dirty from its room, and In house from its own assignment', function () {
    $setup = assignSetup();
    app(AssignBed::class)($setup['user'], checkedInLeavingToday($setup, $setup['dorm'], $setup['bedUnit']), $setup['beds'][0]);
    markDirty($setup['dormRoom']);

    expect(walkInFree($setup['dorm']))->toBe([['D1 · Bed 1', 'In house'], ['D1 · Bed 2', 'Dirty']]);
});

test('an In house bed in a clean room is listed as In house and the other bed stays ready', function () {
    $setup = assignSetup();
    app(AssignBed::class)($setup['user'], checkedInLeavingToday($setup, $setup['dorm'], $setup['bedUnit']), $setup['beds'][0]);

    expect(walkInFree($setup['dorm']))->toBe([['D1 · Bed 1', 'In house'], ['D1 · Bed 2', null]]);
});

test('the free-units route adds not-ready units only for a walk-in', function () {
    $setup = assignSetup();
    app(AssignRoom::class)($setup['user'], checkedInLeavingToday($setup, $setup['roomType'], $setup['unit']), $setup['rooms'][0]);
    markDirty($setup['rooms'][1]);
    $user = User::factory()->for($setup['property'])->withPermissions(['reservations.manage'])->create();
    $query = ['room_type_id' => $setup['roomType']->id, 'arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22'];

    $this->actingAs($user)->getJson(route('reservations.free-units', ['property' => $setup['property']->code, ...$query, 'walk_in' => 1]))
        ->assertOk()
        ->assertJsonCount(2, 'units')
        ->assertJsonPath('units.0.label', '101')
        ->assertJsonPath('units.0.reason', 'In house')
        ->assertJsonPath('units.1.label', '102')
        ->assertJsonPath('units.1.reason', 'Dirty');

    $this->actingAs($user)->getJson(route('reservations.free-units', ['property' => $setup['property']->code, ...$query]))
        ->assertOk()
        ->assertJsonCount(2, 'units')
        ->assertJsonPath('units.0.reason', null)
        ->assertJsonPath('units.1.reason', null);
});
