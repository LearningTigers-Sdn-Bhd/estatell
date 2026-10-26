<?php

use App\Features\DoorLocks;
use App\Models\DoorLock;
use App\Models\DoorLockConnection;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

/**
 * A live property with Door locks on, a signed-in connection, an owner, and one lock linked to room 101.
 *
 * @return array{owner: User, property: Property, connection: DoorLockConnection, room: Room, lock: DoorLock}
 */
function doorLockSetup(bool $gateway = false, bool $feature = true, bool $enabled = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['time_zone' => 'Asia/Kuala_Lumpur']);

    if ($feature) {
        Feature::for($property)->activate(DoorLocks::class);
    }

    $connection = DoorLockConnection::factory()->for($property)->create(['enabled' => $enabled]);
    $room = Room::factory()->create(['property_id' => $property->id, 'number' => '101']);
    $lock = DoorLock::factory()->for($property)->create(['room_id' => $room->id, 'has_gateway' => $gateway]);

    return compact('owner', 'property', 'connection', 'room', 'lock');
}

/**
 * A checked-in unit that sleeps in `$room` from 2026-10-14 to 2026-10-16 (departure 11:00).
 */
function doorLockStay(Property $property, Room $room): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create([
        'reservation_id' => $reservation->id,
        'arrival_date' => '2026-10-14',
        'departure_date' => '2026-10-16',
    ]);

    foreach (['2026-10-14', '2026-10-15'] as $date) {
        UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => $date, 'room_id' => $room->id]);
    }

    return $unit;
}

function fakeTtlock(): void
{
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'at-new', 'refresh_token' => 'rt-new', 'expires_in' => 7776000]),
        '*/v3/lock/list' => Http::response(['list' => [['lockId' => 555, 'lockAlias' => 'Room 101 lock', 'electricQuantity' => 80, 'hasGateway' => 1]], 'pages' => 1]),
        '*/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '482913', 'keyboardPwdId' => 9001]),
        '*/v3/keyboardPwd/add' => Http::response(['keyboardPwdId' => 9002]),
        '*/v3/keyboardPwd/delete' => Http::response(['errcode' => 0]),
        '*/v3/lock/unlock' => Http::response(['errcode' => 0]),
    ]);
}

function ttlockCalls(string $path): int
{
    return Http::recorded(fn ($request): bool => str_contains($request->url(), $path))->count();
}
