<?php

use App\Enums\BillingMode;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitChild;
use App\Models\Room;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a reservation factory builds a confirmed direct reservation with a reference from the property sequence', function () {
    $reservation = Reservation::factory()->create();
    $second = Reservation::factory()->for($reservation->property)->create();

    expect($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->billing_mode)->toBe(BillingMode::MasterPays)
        ->and($reservation->source)->toBe(ReservationSource::Direct)
        ->and($reservation->reference)->toMatch('/^[A-Z0-9]+-\d{2}1\d{5}$/')
        ->and($second->reference)->not->toBe($reservation->reference)
        ->and($reservation->booker->property_id)->toBe($reservation->property_id)
        ->and($reservation->creator->property_id)->toBe($reservation->property_id);
});

test('a reference is unique within a property', function () {
    $reservation = Reservation::factory()->create();

    expect(fn () => DB::transaction(fn () => Reservation::factory()->for($reservation->property)->create(['reference' => $reservation->reference])))
        ->toThrow(QueryException::class);

    expect(Reservation::factory()->create(['reference' => $reservation->reference]))->toBeInstanceOf(Reservation::class);
});

test('a unit has nights, occupants and child ages and its quoted amounts keep their decimals', function () {
    $unit = ReservationUnit::factory()->withNights()->create(['children' => 1]);
    UnitGuest::factory()->for($unit)->primary()->create();
    ReservationUnitChild::factory()->for($unit)->create(['age' => 0]);

    expect($unit->status)->toBe(UnitStatus::Confirmed)
        ->and($unit->property_id)->toBe($unit->reservation->property_id)
        ->and($unit->nights()->count())->toBe(3)
        ->and($unit->nights()->orderBy('date')->first()->amount)->toBe('100.00')
        ->and($unit->guests()->count())->toBe(1)
        ->and($unit->childAges()->sole()->age)->toBe(0)
        ->and($unit->arrival_date->toDateString())->toBe('2026-11-02')
        ->and($unit->reservation->units()->count())->toBe(1);
});

test('the database rejects invalid values', function (Closure $attempt) {
    expect($attempt)->toThrow(QueryException::class);
})->with([
    'a reservation status outside the enum' => [fn () => DB::transaction(fn () => DB::table('reservations')->where('id', Reservation::factory()->create()->id)->update(['status' => 'open']))],
    'the old agent source' => [fn () => DB::transaction(fn () => DB::table('reservations')->where('id', Reservation::factory()->create()->id)->update(['source' => 'agent']))],
    'a billing mode outside the enum' => [fn () => DB::transaction(fn () => DB::table('reservations')->where('id', Reservation::factory()->create()->id)->update(['billing_mode' => 'nobody']))],
    'a blank group name' => [fn () => DB::transaction(fn () => Reservation::factory()->create(['group_name' => '  ']))],
    'a cancellation request without a user' => [fn () => DB::transaction(fn () => Reservation::factory()->create(['cancellation_requested_at' => now()]))],
    'a departure on the arrival date' => [fn () => DB::transaction(fn () => ReservationUnit::factory()->create(['departure_date' => '2026-11-02']))],
    'no adults' => [fn () => DB::transaction(fn () => ReservationUnit::factory()->create(['adults' => 0]))],
    'a checked-in unit without a check-in time' => [fn () => DB::transaction(fn () => ReservationUnit::factory()->create(['status' => UnitStatus::CheckedIn]))],
    'a confirmed unit with a check-out time' => [fn () => DB::transaction(fn () => ReservationUnit::factory()->create(['checked_in_at' => now(), 'checked_out_at' => now()]))],
    'a negative child age' => [fn () => DB::transaction(fn () => ReservationUnitChild::factory()->create(['age' => -1]))],
]);

test('a reservation can come from the company portal', function () {
    expect(Reservation::factory()->create(['source' => ReservationSource::Portal])->refresh()->source)->toBe(ReservationSource::Portal);
});

test('a cancellation request needs the time and the user together', function () {
    $user = User::factory()->create();
    $reservation = Reservation::factory()->create([
        'cancellation_requested_at' => now(),
        'cancellation_requested_by' => $user->id,
    ]);

    expect($reservation->cancellationRequester->is($user))->toBeTrue();
});

test('a night is unique per unit and date and its amount must equal its parts', function () {
    $unit = ReservationUnit::factory()->create();
    UnitNight::factory()->for($unit)->create(['date' => '2026-11-02']);

    expect(fn () => DB::transaction(fn () => UnitNight::factory()->for($unit)->create(['date' => '2026-11-02'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => UnitNight::factory()->for($unit)->create(['date' => '2026-11-03', 'amount' => '99.00'])))->toThrow(QueryException::class);

    $night = UnitNight::factory()->for($unit)->create([
        'date' => '2026-11-03',
        'base_amount' => '100.00', 'extra_amount' => '20.00', 'supplement_amount' => '10.00', 'discount_amount' => '15.00', 'amount' => '115.00',
    ]);

    expect($night->amount)->toBe('115.00');
});

test('a night is assigned to a room or a bed, never both', function () {
    $unit = ReservationUnit::factory()->create();
    $room = Room::factory()->create(['property_id' => $unit->property_id]);
    $bed = Bed::factory()->create(['room_id' => $room->id]);

    expect(fn () => DB::transaction(fn () => UnitNight::factory()->for($unit)->create(['room_id' => $room->id, 'bed_id' => $bed->id])))->toThrow(QueryException::class);

    UnitNight::factory()->for($unit)->create(['date' => '2026-11-02', 'room_id' => $room->id]);
    UnitNight::factory()->for($unit)->create(['date' => '2026-11-03', 'bed_id' => $bed->id]);

    expect($unit->nights()->count())->toBe(2);
});

test('a unit has each guest once and one primary guest', function () {
    $unit = ReservationUnit::factory()->create();
    $first = UnitGuest::factory()->for($unit)->primary()->create();

    expect(fn () => DB::transaction(fn () => UnitGuest::factory()->for($unit)->create(['guest_id' => $first->guest_id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => UnitGuest::factory()->for($unit)->primary()->create()))->toThrow(QueryException::class);

    UnitGuest::factory()->for($unit)->create();
    UnitGuest::factory()->for(ReservationUnit::factory())->primary()->create();

    expect($unit->guests()->count())->toBe(2);
});
