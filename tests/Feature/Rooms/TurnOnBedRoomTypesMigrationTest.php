<?php

use App\Features\BedRoomTypes;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

function runTurnOnBedRoomTypesMigration(): void
{
    (include database_path('migrations/2026_11_16_005729_turn_on_bed_room_types_where_beds_are_used.php'))->up();
    Feature::flushCache();
}

test('the migration turns beds on only where a bed room type or a per bed plan exists', function () {
    $withBedType = Property::factory()->create();
    RoomType::factory()->for($withBedType)->bed()->archived()->create();
    $withPerBedPlan = Property::factory()->create();
    RatePlan::factory()->for($withPerBedPlan)->perBed()->create();
    $roomsOnly = Property::factory()->create();
    RoomType::factory()->for($roomsOnly)->create();
    RatePlan::factory()->for($roomsOnly)->create();

    runTurnOnBedRoomTypesMigration();
    runTurnOnBedRoomTypesMigration();

    expect(Feature::for($withBedType)->active(BedRoomTypes::class))->toBeTrue()
        ->and(Feature::for($withPerBedPlan)->active(BedRoomTypes::class))->toBeTrue()
        ->and(Feature::for($roomsOnly)->active(BedRoomTypes::class))->toBeFalse()
        ->and(DB::table('features')->where('name', 'bed-room-types')->where('value', 'true')->count())->toBe(2);
});

test('the migration does nothing when no property sells beds', function () {
    RoomType::factory()->create();

    runTurnOnBedRoomTypesMigration();

    expect(DB::table('features')->where('name', 'bed-room-types')->count())->toBe(0);
});
