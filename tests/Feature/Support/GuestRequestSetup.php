<?php

use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Models\User;

require_once __DIR__.'/KioskSetup.php';

/**
 * The owner of a live property with an open business date (the frozen day, 2026-10-14), and a guest checked in to room
 * 101 for two nights.
 *
 * @return array{owner: User, property: Property, unit: ReservationUnit, room: Room}
 */
function guestRequestSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();

    if ($property->settings === null) {
        PropertySetting::factory()->for($property)->create();
    }

    BusinessDate::factory()->create(['property_id' => $property->id]);
    $stay = kioskStayFor($property, 'R-100', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $stay['unit']->room_type_id, 'number' => '101']);

    foreach (['2026-10-14', '2026-10-15'] as $date) {
        UnitNight::factory()->create(['reservation_unit_id' => $stay['unit']->id, 'date' => $date, 'room_id' => $room->id]);
    }

    return ['owner' => $owner, 'property' => $property->refresh(), 'unit' => $stay['unit'], 'room' => $room];
}
