<?php

use App\Features\DoorLocks;
use App\Models\DoorLock;
use App\Models\KeyportDevice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

require_once __DIR__.'/DoorLockSetup.php';

/**
 * A live property (Kuala Lumpur time) with Door locks on, an owner, and one paired Keyport PC. The claim does not wait.
 *
 * @return array{owner: User, property: Property, device: KeyportDevice, token: string}
 */
function keyportSetup(bool $feature = true): array
{
    config(['keyport.poll_seconds' => 0]);

    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['time_zone' => 'Asia/Kuala_Lumpur']);

    if ($feature) {
        Feature::for($property)->activate(DoorLocks::class);
    }

    $token = 'kp_live_'.Str::random(40);
    $device = KeyportDevice::factory()->for($property)->withToken($token)->create();

    return compact('owner', 'property', 'device', 'token');
}

/**
 * @return array<string, string>
 */
function keyportHeaders(string $token): array
{
    return ['X-Keyport-Version' => '1', 'Authorization' => 'Bearer '.$token];
}

/**
 * A live property with Door locks on, room 101 with an eLock lock (1.1.101), a checked-in stay in that room, and one paired Keyport PC.
 *
 * @return array{owner: User, property: Property, room: Room, lock: DoorLock, unit: ReservationUnit, device: KeyportDevice}
 */
function keyportElockSetup(): array
{
    ['owner' => $owner, 'property' => $property, 'room' => $room] = doorLockSetup();
    DoorLock::query()->where('property_id', $property->id)->delete();
    $lock = DoorLock::factory()->elock()->for($property)->create(['room_id' => $room->id]);
    $unit = doorLockStay($property, $room);
    $device = KeyportDevice::factory()->for($property)->create();

    return compact('owner', 'property', 'room', 'lock', 'unit', 'device');
}
