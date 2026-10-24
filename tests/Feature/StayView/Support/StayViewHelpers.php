<?php

use App\Models\Bed;
use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;

function stayViewProperty(): Property
{
    $property = User::factory()->owner()->create()->property;
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);

    return $property;
}

/**
 * A unit whose nights are all in `$place` (a Room or a Bed), with a primary guest.
 *
 * @param  array<string, mixed>  $attributes
 */
function stayViewUnit(Property $property, Room|Bed $place, array $attributes = [], ?Reservation $reservation = null, string $guestName = 'Hanami Saki', ?string $state = null): ReservationUnit
{
    $factory = ReservationUnit::factory()->withNights();
    $unit = ($state === null ? $factory : $factory->{$state}())->create([
        'reservation_id' => ($reservation ?? Reservation::factory()->create(['property_id' => $property->id]))->id,
        'arrival_date' => '2026-10-14',
        'departure_date' => '2026-10-17',
        ...$attributes,
    ]);

    UnitNight::query()->where('reservation_unit_id', $unit->id)->update($place instanceof Bed ? ['bed_id' => $place->id] : ['room_id' => $place->id]);
    UnitGuest::factory()->primary()->create([
        'reservation_unit_id' => $unit->id,
        'guest_id' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => $guestName])->id,
    ]);

    return $unit;
}
