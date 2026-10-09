<?php

use App\Enums\PropertyStatus;
use App\Models\Bed;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use Database\Seeders\BookingScenariosSeeder;

test('the booking scenarios seeder builds the property and every scenario', function () {
    $this->seed(BookingScenariosSeeder::class);

    $property = Property::query()->where('name', BookingScenariosSeeder::NAME)->sole();
    $reservations = Reservation::query()->where('property_id', $property->id);

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and(Room::query()->where('property_id', $property->id)->count())->toBe(21)
        ->and(Bed::query()->whereIn('room_id', Room::query()->where('property_id', $property->id)->select('id'))->count())->toBe(6)
        ->and(RatePlan::query()->where('property_id', $property->id)->pluck('name')->all())->toBe(['Room Only', 'Bed & Breakfast', 'Dorm Bed', 'Corporate'])
        ->and((clone $reservations)->count())->toBe(28)
        ->and(ReservationUnit::query()->where('property_id', $property->id)->count())->toBe(59)
        ->and((clone $reservations)->where('notes', 'like', '[G4]%')->sole()->units()->count())->toBe(4);
});

test('running the booking scenarios seeder twice does not add a second property', function () {
    $this->seed(BookingScenariosSeeder::class);
    $this->seed(BookingScenariosSeeder::class);

    expect(Property::query()->where('name', BookingScenariosSeeder::NAME)->count())->toBe(1)
        ->and(Reservation::query()->count())->toBe(28);
});
