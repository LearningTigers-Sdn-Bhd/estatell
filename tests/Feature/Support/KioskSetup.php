<?php

use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use App\Models\UnitGuest;
use Illuminate\Testing\TestResponse;

const KIOSK_TEST_TOKEN = 'est_live_testtokenaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/**
 * A live property with an open business date (the frozen day, 2026-10-14), a paired kiosk with the token above, and a
 * Confirmed stay `R-100` arriving that day for two nights. Its primary guest is Aisha Rahman, a Malaysian with no
 * other details. Pass `unit` to change the stay and `guest` to change the guest.
 *
 * @param  array<string, mixed>  $unit
 * @param  array<string, mixed>  $guest
 * @return array{property: Property, kiosk: Kiosk, reservation: Reservation, unit: ReservationUnit, guest: Guest}
 */
function kioskStaySetup(array $unit = [], array $guest = []): array
{
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $kiosk = Kiosk::factory()->for($property)->withToken(KIOSK_TEST_TOKEN)->create();

    return [...kioskStayFor($property, 'R-100', $unit, $guest), 'property' => $property, 'kiosk' => $kiosk];
}

/**
 * A Confirmed stay with a primary guest in the given property.
 *
 * @param  array<string, mixed>  $unit
 * @param  array<string, mixed>  $guest
 * @return array{reservation: Reservation, unit: ReservationUnit, guest: Guest}
 */
function kioskStayFor(Property $property, string $reference, array $unit = [], array $guest = []): array
{
    $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisha Rahman', 'nationality_code' => null, 'date_of_birth' => null, 'gender' => null, ...$guest]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'reference' => $reference, 'booker_guest_id' => $guest->id]);
    $unit = ReservationUnit::factory()->create([
        'reservation_id' => $reservation->id,
        'room_type_id' => RoomType::query()->where('property_id', $property->id)->where('name', 'Deluxe Double')->first()?->id ?? RoomType::factory()->create(['property_id' => $property->id, 'name' => 'Deluxe Double'])->id,
        'arrival_date' => '2026-10-14',
        'departure_date' => '2026-10-16',
        ...$unit,
    ]);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id]);

    return ['reservation' => $reservation, 'unit' => $unit, 'guest' => $guest];
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function kioskPost(string $path, array $body = [], ?string $token = KIOSK_TEST_TOKEN, array $headers = []): TestResponse
{
    return test()->postJson('/api/kiosk/v1'.$path, $body, [...($token === null ? [] : ['Authorization' => 'Bearer '.$token]), ...$headers]);
}

/**
 * @param  array<string, string>  $headers
 */
function kioskRead(string $path, ?string $token = KIOSK_TEST_TOKEN, array $headers = []): TestResponse
{
    return test()->getJson('/api/kiosk/v1'.$path, [...($token === null ? [] : ['Authorization' => 'Bearer '.$token]), ...$headers]);
}
