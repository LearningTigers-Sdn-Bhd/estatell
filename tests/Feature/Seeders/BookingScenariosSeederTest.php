<?php

use App\Enums\BusinessDateStatus;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Services\Property\PropertyToday;
use Database\Seeders\BookingScenariosSeeder;

/**
 * The reservation of a scenario, found by the code at the start of its notes.
 */
function scenario(string $code): Reservation
{
    return Reservation::query()->where('notes', 'like', "[{$code}]%")->sole();
}

/**
 * Room night lines posted for a reservation: one per unit night, not counting their tax lines or reversals.
 */
function postedNights(Reservation $reservation): int
{
    $nightIds = UnitNight::query()->whereIn('reservation_unit_id', $reservation->units()->select('id'))->select('id');

    return FolioLine::query()
        ->where('source_type', (new UnitNight)->getMorphClass())
        ->whereIn('source_id', $nightIds)
        ->whereNull('parent_line_id')
        ->whereNull('reversal_of_id')
        ->count();
}

test('the booking scenarios seeder builds the property and rolls it forward to today', function () {
    $this->seed(BookingScenariosSeeder::class);

    $property = Property::query()->where('name', BookingScenariosSeeder::NAME)->sole();
    $today = app(PropertyToday::class)->date($property)->toDateString();

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and(Room::query()->where('property_id', $property->id)->count())->toBe(21)
        ->and(Bed::query()->whereIn('room_id', Room::query()->where('property_id', $property->id)->select('id'))->count())->toBe(6)
        ->and(RatePlan::query()->where('property_id', $property->id)->orderBy('id')->pluck('name')->all())->toBe(['Room Only', 'Bed & Breakfast', 'Dorm Bed', 'Corporate'])
        ->and(Reservation::query()->where('property_id', $property->id)->count())->toBe(28)
        ->and(ReservationUnit::query()->where('property_id', $property->id)->count())->toBe(60)
        ->and($property->businessDates()->where('status', BusinessDateStatus::Closed)->count())->toBe(BookingScenariosSeeder::DAYS_BACK)
        ->and($property->businessDates()->where('status', BusinessDateStatus::Open)->sole()->date->toDateString())->toBe($today);
});

test('every scenario reaches its expected status', function () {
    $this->seed(BookingScenariosSeeder::class);

    $expected = [
        'S1' => ReservationStatus::Completed, 'S2' => ReservationStatus::Confirmed, 'S3' => ReservationStatus::Confirmed,
        'S4' => ReservationStatus::Confirmed, 'S5' => ReservationStatus::Confirmed, 'S6' => ReservationStatus::Confirmed,
        'S7' => ReservationStatus::Confirmed, 'S8' => ReservationStatus::InHouse, 'S9' => ReservationStatus::InHouse,
        'S10' => ReservationStatus::Completed, 'S11' => ReservationStatus::InHouse, 'S12' => ReservationStatus::Confirmed,
        'S13' => ReservationStatus::Confirmed, 'S14' => ReservationStatus::Confirmed,
        'G1' => ReservationStatus::InHouse, 'G2' => ReservationStatus::InHouse, 'G3' => ReservationStatus::InHouse,
        'G4' => ReservationStatus::Confirmed, 'G5' => ReservationStatus::Cancelled, 'G6' => ReservationStatus::Completed,
        'G7' => ReservationStatus::Confirmed, 'G8' => ReservationStatus::Confirmed, 'G9' => ReservationStatus::Confirmed,
        'G10' => ReservationStatus::Confirmed,
        'B1' => ReservationStatus::InHouse, 'B2' => ReservationStatus::InHouse, 'B3' => ReservationStatus::Confirmed,
        'B4' => ReservationStatus::Confirmed,
    ];

    $actual = array_map(fn (string $code): ReservationStatus => scenario($code)->status, array_combine(array_keys($expected), array_keys($expected)));

    expect($actual)->toBe($expected)
        ->and(scenario('G6')->units()->pluck('status')->all())->toEqualCanonicalizing([UnitStatus::Cancelled, UnitStatus::NoShow])
        ->and(scenario('B2')->units()->orderBy('id')->pluck('status')->all())->toBe([UnitStatus::CheckedOut, UnitStatus::CheckedIn, UnitStatus::CheckedIn, UnitStatus::NoShow])
        ->and(scenario('G9')->units()->count())->toBe(3);
});

test('the night audit posts room charges only for stays that slept', function () {
    $this->seed(BookingScenariosSeeder::class);

    expect(postedNights(scenario('S1')))->toBe(2)
        ->and(postedNights(scenario('S9')))->toBe(4)
        ->and(postedNights(scenario('G6')))->toBe(0)
        ->and(postedNights(scenario('S14')))->toBe(0);
});

test('running the booking scenarios seeder twice does not add a second property', function () {
    $this->seed(BookingScenariosSeeder::class);
    $this->seed(BookingScenariosSeeder::class);

    expect(Property::query()->where('name', BookingScenariosSeeder::NAME)->count())->toBe(1)
        ->and(Reservation::query()->count())->toBe(28);
});
