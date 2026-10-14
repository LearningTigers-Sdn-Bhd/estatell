<?php

use App\Actions\Reservations\SyncReservationStatus;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;

test('the status follows the units', function () {
    $reservation = Reservation::factory()->create();
    $first = ReservationUnit::factory()->for($reservation)->create();
    ReservationUnit::factory()->for($reservation)->create();

    $first->forceFill(['status' => 'checked_in', 'checked_in_at' => now()])->save();

    expect(app(SyncReservationStatus::class)($reservation))->toBeTrue()
        ->and($reservation->status)->toBe(ReservationStatus::InHouse)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::InHouse);
});

test('a reservation whose units are all cancelled becomes cancelled', function () {
    $reservation = Reservation::factory()->create();
    ReservationUnit::factory()->for($reservation)->cancelled()->count(2)->create();

    expect(app(SyncReservationStatus::class)($reservation))->toBeTrue()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Cancelled);
});

test('nothing is written when the status already matches', function () {
    $reservation = Reservation::factory()->create();
    ReservationUnit::factory()->for($reservation)->create();
    $updatedAt = $reservation->fresh()->updated_at;

    $this->travel(5)->minutes();

    expect(app(SyncReservationStatus::class)($reservation))->toBeFalse()
        ->and($reservation->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('a stale model does not decide the status', function () {
    $reservation = Reservation::factory()->create();
    $unit = ReservationUnit::factory()->for($reservation)->checkedIn()->create();
    $stale = Reservation::query()->findOrFail($reservation->id);

    app(SyncReservationStatus::class)($reservation);
    $unit->forceFill(['status' => 'checked_out', 'checked_out_at' => now()])->save();

    expect(app(SyncReservationStatus::class)($stale))->toBeTrue()
        ->and($stale->status)->toBe(ReservationStatus::Completed)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Completed);
});

test('another reservation is left alone', function () {
    $reservation = Reservation::factory()->create();
    $other = Reservation::factory()->create();
    ReservationUnit::factory()->for($reservation)->cancelled()->create();
    ReservationUnit::factory()->for($other)->create();

    app(SyncReservationStatus::class)($reservation);

    expect($other->fresh()->status)->toBe(ReservationStatus::Confirmed);
});
