<?php

use App\Enums\DefaultRoleEnum;
use App\Models\Bed;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

/**
 * A property with a Room room type of two rooms (101, 102) and a dorm of three beds, a plan for each priced for
 * 2026-10-20 to 2026-10-27, a user and an existing guest.
 *
 * @return array{user: User, property: Property, roomType: RoomType, rooms: list<Room>, plan: RatePlan, dorm: RoomType, beds: list<Bed>, bedPlan: RatePlan, guest: Guest}
 */
function bookingSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    $roomType = RoomType::factory()->for($property)->create();
    $rooms = [
        Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']),
        Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '102']),
    ];
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);
    $dorm = RoomType::factory()->bed()->for($property)->create();
    $dormRoom = Room::factory()->for($property)->create(['room_type_id' => $dorm->id, 'number' => 'D1']);
    $beds = array_map(fn (int $number): Bed => Bed::factory()->create(['room_id' => $dormRoom->id, 'label' => "Bed {$number}"]), [1, 2, 3]);
    $bedPlan = RatePlan::factory()->perBed()->for($property)->create();
    $bedPlan->roomTypes()->attach($dorm);

    for ($night = 0; $night < 7; $night++) {
        $date = now()->setDate(2026, 10, 20 + $night)->toDateString();
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => $date, 'amount' => '100.00']);
        Rate::factory()->create(['rate_plan_id' => $bedPlan->id, 'room_type_id' => $dorm->id, 'date' => $date, 'amount' => '40.00']);
    }

    return [
        'user' => $user,
        'property' => $property,
        'roomType' => $roomType,
        'rooms' => $rooms,
        'plan' => $plan,
        'dorm' => $dorm,
        'beds' => $beds,
        'bedPlan' => $bedPlan,
        'guest' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60123456789']),
    ];
}

/**
 * @param  list<string>  $permissions
 */
function actionsRoleWith(User $user, array $permissions): void
{
    $role = Role::query()->create(['property_id' => $user->property_id, 'name' => 'Custom']);
    setPermissionsTeamId($user->property_id);
    $role->givePermissionTo($permissions);
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
