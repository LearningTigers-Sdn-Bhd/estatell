<?php

use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\SandbaySeeder;

test('the Sandbay seeder builds a live resort with rooms, plans, guests and reservations', function () {
    $this->seed(SandbaySeeder::class);

    $property = Property::query()->where('name', 'Sandbay Resort')->sole();

    expect($property->status)->toBe(PropertyStatus::Live)
        ->and($property->businessDates()->count())->toBe(1)
        ->and(Room::query()->where('property_id', $property->id)->count())->toBe(30)
        ->and(RatePlan::query()->where('property_id', $property->id)->pluck('name')->all())
        ->toBe(['Room Only', 'Bed & Breakfast', 'Half Board', 'Dive Package'])
        ->and(Guest::query()->where('property_id', $property->id)->count())->toBe(25)
        ->and(User::query()->where('property_id', $property->id)->count())->toBe(4)
        ->and(Reservation::query()->where('property_id', $property->id)->count())->toBe(19)
        ->and(Reservation::query()->where('status', ReservationStatus::Cancelled)->count())->toBe(1)
        ->and(Reservation::query()->whereNotNull('group_name')->count())->toBe(1);
});

test('running the Sandbay seeder twice does not add a second property', function () {
    $this->seed(SandbaySeeder::class);
    $this->seed(SandbaySeeder::class);

    expect(Property::query()->where('name', 'Sandbay Resort')->count())->toBe(1)
        ->and(Reservation::query()->count())->toBe(19);
});
