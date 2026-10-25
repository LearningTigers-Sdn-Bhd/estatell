<?php

use App\Enums\DefaultRoleEnum;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UnitNight;
use App\Models\User;

/**
 * A live property with an open business date (today), a plan sold on a Standard room type (rooms 101 to 103) and a
 * Deluxe room type (room 201), and a Confirmed unit that arrives today for three nights in room 101.
 *
 * @param  array<string, mixed>  $unit
 * @return array{user: User, property: Property, standard: RoomType, deluxe: RoomType, rooms: array<string, Room>, plan: RatePlan, reservation: Reservation, unit: ReservationUnit}
 */
function moveSetup(array $unit = [], bool $linkDeluxe = true): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    $standard = RoomType::factory()->for($property)->create(['name' => 'Standard']);
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $rooms = [];

    foreach ([['101', $standard], ['102', $standard], ['103', $standard], ['201', $deluxe]] as $position => [$number, $type]) {
        $rooms[$number] = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => $number, 'position' => $position]);
    }

    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($linkDeluxe ? [$standard->id, $deluxe->id] : [$standard->id]);

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $standard->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => today()->toDateString(),
        'departure_date' => today()->addDays(3)->toDateString(),
        ...$unit,
    ]);
    $unit->nights()->update(['room_id' => $rooms['101']->id]);

    return compact('user', 'property', 'standard', 'deluxe', 'rooms', 'plan', 'reservation', 'unit');
}

/**
 * A second Confirmed unit of the property in the given room for the same three nights.
 *
 * @param  array<string, mixed>  $setup
 */
function moveOtherUnit(array $setup, Room $room, string $arrival = '+0 days', string $departure = '+3 days'): ReservationUnit
{
    $other = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $setup['property']->id]))->withNights()->create([
        'room_type_id' => $room->room_type_id,
        'rate_plan_id' => $setup['plan']->id,
        'arrival_date' => today()->modify($arrival)->toDateString(),
        'departure_date' => today()->modify($departure)->toDateString(),
    ]);
    $other->nights()->update(['room_id' => $room->id]);

    return $other;
}

/** @return list<string|null> */
function moveRooms(ReservationUnit $unit): array
{
    return $unit->nights()->orderBy('date')->with('room')->get()->map(fn (UnitNight $night): ?string => $night->room?->number)->all();
}
