<?php

use App\Actions\Guests\MergeGuests;
use App\Actions\Reservations\RepointGuestReservations;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('the booker and the unit occupants move to the survivor', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create();
    $reservation = Reservation::factory()->for($property)->create(['booker_guest_id' => $merged->id]);
    $unit = ReservationUnit::factory()->for($reservation)->create();
    UnitGuest::factory()->for($unit)->primary()->create(['guest_id' => $merged->id]);

    expect(app(MergeGuests::class)($owner, $survivor, $merged))->toBeTrue();

    expect($reservation->fresh()->booker_guest_id)->toBe($survivor->id)
        ->and($unit->guests()->pluck('guest_id')->all())->toBe([$survivor->id])
        ->and($unit->guests()->sole()->is_primary)->toBeTrue();

    $log = Activity::query()->where('event', 'guest.merged')->sole();

    expect($log->properties['reservations_moved'])->toBe(1)
        ->and($log->properties['unit_guests_moved'])->toBe(1)
        ->and($log->properties['unit_guest_duplicates_removed'])->toBe(0);
});

test('a guest on the same unit twice leaves one row and keeps the primary flag', function () {
    $property = User::factory()->create()->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->create();
    UnitGuest::factory()->for($unit)->create(['guest_id' => $survivor->id]);
    UnitGuest::factory()->for($unit)->primary()->create(['guest_id' => $merged->id]);

    $moved = app(RepointGuestReservations::class)($survivor, $merged);

    $row = $unit->guests()->sole();

    expect($moved)->toBe(['reservations' => 0, 'unit_guests' => 0, 'duplicates_removed' => 1])
        ->and($row->guest_id)->toBe($survivor->id)
        ->and($row->is_primary)->toBeTrue();
});

test('the survivor stays primary when it already was and a second occupant keeps its place', function () {
    $property = User::factory()->create()->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->create();
    UnitGuest::factory()->for($unit)->primary()->create(['guest_id' => $survivor->id]);
    UnitGuest::factory()->for($unit)->create(['guest_id' => $merged->id]);

    $moved = app(RepointGuestReservations::class)($survivor, $merged);

    expect($moved['duplicates_removed'])->toBe(1)
        ->and($unit->guests()->count())->toBe(1)
        ->and($unit->guests()->sole()->is_primary)->toBeTrue();
});

test('a merged guest on a unit alone moves with the primary flag', function () {
    $property = User::factory()->create()->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create();
    $other = Guest::factory()->for($property)->create();
    $unit = ReservationUnit::factory()->for(Reservation::factory()->for($property))->create();
    UnitGuest::factory()->for($unit)->primary()->create(['guest_id' => $other->id]);
    UnitGuest::factory()->for($unit)->create(['guest_id' => $merged->id]);

    $moved = app(RepointGuestReservations::class)($survivor, $merged);

    expect($moved['unit_guests'])->toBe(1)
        ->and($unit->guests()->pluck('guest_id')->sort()->values()->all())->toBe(collect([$other->id, $survivor->id])->sort()->values()->all());
});

test('other guests and other properties are left alone', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $survivor = Guest::factory()->for($property)->create();
    $merged = Guest::factory()->for($property)->create();
    $bystander = Guest::factory()->for($property)->create();
    $reservation = Reservation::factory()->for($property)->create(['booker_guest_id' => $bystander->id]);
    $unit = ReservationUnit::factory()->for($reservation)->create();
    UnitGuest::factory()->for($unit)->create(['guest_id' => $bystander->id]);

    $elsewhere = Guest::factory()->create();
    $foreign = Reservation::factory()->create(['booker_guest_id' => $elsewhere->id]);

    app(MergeGuests::class)($owner, $survivor, $merged);

    expect($reservation->fresh()->booker_guest_id)->toBe($bystander->id)
        ->and($unit->guests()->sole()->guest_id)->toBe($bystander->id)
        ->and($foreign->fresh()->booker_guest_id)->toBe($elsewhere->id);
});

test('a refused merge moves no reservation', function () {
    $owner = User::factory()->owner()->create();
    $survivor = Guest::factory()->for($owner->property)->create();
    $merged = Guest::factory()->create();
    $reservation = Reservation::factory()->create(['property_id' => $merged->property_id, 'booker_guest_id' => $merged->id]);

    expect(app(MergeGuests::class)($owner, $survivor, $merged))->toBeFalse()
        ->and($reservation->fresh()->booker_guest_id)->toBe($merged->id);
});
