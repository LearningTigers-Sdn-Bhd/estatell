<?php

use App\Models\Bed;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A property with a Room room type of two rooms and a Bed room type with one room of two beds, a user, and a
 * Confirmed unit of each type from 2026-10-20 to 2026-10-22.
 *
 * @return array{user: User, property: Property, roomType: RoomType, rooms: list<Room>, dorm: RoomType, dormRoom: Room, beds: list<Bed>, unit: ReservationUnit, bedUnit: ReservationUnit}
 */
function assignSetup(): array
{
    $user = User::factory()->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $rooms = [
        Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']),
        Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '102']),
    ];
    $dorm = RoomType::factory()->bed()->for($property)->create();
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => $dorm->id, 'number' => 'D1']);
    $beds = [
        Bed::factory()->create(['room_id' => $dormRoom->id, 'label' => 'Bed 1']),
        Bed::factory()->create(['room_id' => $dormRoom->id, 'label' => 'Bed 2']),
    ];

    return [
        'user' => $user,
        'property' => $property,
        'roomType' => $roomType,
        'rooms' => $rooms,
        'dorm' => $dorm,
        'dormRoom' => $dormRoom,
        'beds' => $beds,
        'unit' => assignUnit($property, $roomType),
        'bedUnit' => assignUnit($property, $dorm),
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function assignUnit(Property $property, RoomType $roomType, array $attributes = []): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);

    return ReservationUnit::factory()->for($reservation)->withNights()->create([
        'room_type_id' => $roomType->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        ...$attributes,
    ]);
}
