<?php

use App\Actions\Reservations\AssignBed;
use App\Actions\Reservations\AssignRoom;
use App\Models\RoomBlock;
use App\Services\Availability\FreeUnits;

test('every active room is free when nothing is assigned or blocked', function () {
    $setup = assignSetup();
    $setup['rooms'][1]->forceFill(['archived_at' => now()])->save();

    $free = app(FreeUnits::class)->forStay($setup['roomType'], now()->setDate(2026, 10, 20), now()->setDate(2026, 10, 22));

    expect(array_map(fn ($unit) => [$unit->id, $unit->label], $free))->toBe([[$setup['rooms'][0]->id, '101']]);
});

test('a room assigned to or blocked on any night of the stay is not free', function () {
    $setup = assignSetup();
    app(AssignRoom::class)($setup['user'], assignUnit($setup['property'], $setup['roomType'], ['arrival_date' => '2026-10-22', 'departure_date' => '2026-10-24']), $setup['rooms'][0]);

    $stay = fn (string $from, string $to) => array_map(fn ($unit) => $unit->label, app(FreeUnits::class)->forStay($setup['roomType'], now()->setDate(2026, 10, (int) substr($from, 8)), now()->setDate(2026, 10, (int) substr($to, 8))));

    expect($stay('2026-10-20', '2026-10-22'))->toBe(['101', '102'])
        ->and($stay('2026-10-21', '2026-10-23'))->toBe(['102']);

    RoomBlock::factory()->create(['room_id' => $setup['rooms'][1]->id, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21']);

    expect($stay('2026-10-21', '2026-10-23'))->toBe([])
        ->and($stay('2026-10-24', '2026-10-25'))->toBe(['101', '102']);
});

test('beds are listed with their room and a booked bed is left out', function () {
    $setup = assignSetup();
    app(AssignBed::class)($setup['user'], $setup['bedUnit'], $setup['beds'][0]);

    $free = app(FreeUnits::class)->forStay($setup['dorm'], now()->setDate(2026, 10, 20), now()->setDate(2026, 10, 22));

    expect(array_map(fn ($unit) => [$unit->id, $unit->label], $free))->toBe([[$setup['beds'][1]->id, 'D1 · Bed 2']]);
});

test('the unit being changed does not count against its own room, and a stay with no nights has none free', function () {
    $setup = assignSetup();
    app(AssignRoom::class)($setup['user'], $setup['unit'], $setup['rooms'][0]);
    $service = app(FreeUnits::class);
    $arrival = now()->setDate(2026, 10, 20);
    $departure = now()->setDate(2026, 10, 22);

    expect(array_map(fn ($unit) => $unit->label, $service->forStay($setup['roomType'], $arrival, $departure)))->toBe(['102'])
        ->and(array_map(fn ($unit) => $unit->label, $service->forStay($setup['roomType'], $arrival, $departure, $setup['unit']->id)))->toBe(['101', '102'])
        ->and($service->forStay($setup['roomType'], $arrival, $arrival))->toBe([]);
});
