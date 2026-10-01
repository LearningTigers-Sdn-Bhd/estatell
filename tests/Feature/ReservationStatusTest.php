<?php

use App\Enums\ReservationStatus;
use App\Enums\Tone;
use App\Enums\UnitStatus;

test('a reservation status is derived from its unit statuses', function (array $units, ReservationStatus $expected) {
    expect(ReservationStatus::fromUnits($units))->toBe($expected);
})->with([
    'one confirmed unit' => [[UnitStatus::Confirmed], ReservationStatus::Confirmed],
    'every unit cancelled' => [[UnitStatus::Cancelled, UnitStatus::Cancelled], ReservationStatus::Cancelled],
    'only no-show and cancelled units left' => [[UnitStatus::NoShow, UnitStatus::Cancelled], ReservationStatus::Completed],
    'every unit no-show' => [[UnitStatus::NoShow, UnitStatus::NoShow], ReservationStatus::Completed],
    'a checked-in unit' => [[UnitStatus::CheckedIn, UnitStatus::Confirmed], ReservationStatus::InHouse],
    'a checked-in unit beside a cancelled one' => [[UnitStatus::CheckedIn, UnitStatus::Cancelled], ReservationStatus::InHouse],
    'every live unit checked out' => [[UnitStatus::CheckedOut, UnitStatus::CheckedOut], ReservationStatus::Completed],
    'checked out beside cancelled and no-show' => [[UnitStatus::CheckedOut, UnitStatus::Cancelled, UnitStatus::NoShow], ReservationStatus::Completed],
    'staggered group: one checked out, one still to arrive' => [[UnitStatus::CheckedOut, UnitStatus::Confirmed], ReservationStatus::Confirmed],
    'staggered group: one checked out, one to arrive, one cancelled' => [[UnitStatus::CheckedOut, UnitStatus::Confirmed, UnitStatus::Cancelled], ReservationStatus::Confirmed],
    'confirmed beside no-show' => [[UnitStatus::Confirmed, UnitStatus::NoShow], ReservationStatus::Confirmed],
    'no units' => [[], ReservationStatus::Confirmed],
]);

test('the unit transition graph allows only the listed moves', function () {
    $allowed = [
        'confirmed' => ['checked_in', 'cancelled', 'no_show'],
        'checked_in' => ['checked_out'],
        'checked_out' => [],
        'cancelled' => ['confirmed'],
        'no_show' => ['confirmed'],
    ];

    foreach (UnitStatus::cases() as $from) {
        $reachable = array_map(
            fn (UnitStatus $to): string => $to->value,
            array_values(array_filter(UnitStatus::cases(), fn (UnitStatus $to): bool => $from->canTransitionTo($to))),
        );

        expect($reachable)->toBe($allowed[$from->value]);
    }
});

test('only confirmed, checked-in and checked-out units hold inventory', function () {
    expect(UnitStatus::holdingInventory())->toBe([UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut])
        ->and(UnitStatus::Cancelled->holdsInventory())->toBeFalse()
        ->and(UnitStatus::NoShow->holdsInventory())->toBeFalse();
});

test('every status has a label and a tone and the options list every case', function () {
    foreach ([...UnitStatus::cases(), ...ReservationStatus::cases()] as $status) {
        expect($status->label())->toBeString()->not->toBe('')
            ->and($status->tone())->toBeInstanceOf(Tone::class);
    }

    expect(UnitStatus::options())->toHaveCount(5)
        ->and(ReservationStatus::options())->toHaveCount(4);
});
