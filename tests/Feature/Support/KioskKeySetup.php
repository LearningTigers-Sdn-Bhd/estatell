<?php

use App\Enums\UnitStatus;
use App\Features\DoorLocks;
use App\Models\DoorLock;
use App\Models\DoorLockConnection;
use App\Models\Room;
use App\Models\UnitNight;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

require_once __DIR__.'/KioskSetup.php';
require_once __DIR__.'/DoorLockSetup.php';

/** @return array<string, mixed> */
function kioskKeySetup(): array
{
    Http::preventStrayRequests();
    $setup = kioskStaySetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
    $property = $setup['property'];
    Feature::for($property)->activate(DoorLocks::class);
    $connection = DoorLockConnection::factory()->for($property)->create();
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $setup['unit']->room_type_id, 'number' => '101']);
    $lock = DoorLock::factory()->for($property)->create(['room_id' => $room->id, 'has_gateway' => true]);
    foreach (['2026-10-14', '2026-10-15'] as $date) {
        UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'room_id' => $room->id, 'date' => $date]);
    }
    activity()->performedOn($setup['unit'])->event('kiosk.checked_in')->withProperties(['kiosk_id' => $setup['kiosk']->id])->log('Kiosk check-in');

    return [...$setup, 'connection' => $connection, 'room' => $room, 'lock' => $lock];
}
