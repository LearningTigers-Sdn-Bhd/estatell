<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PropertyStatus;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertyGroup;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;

/**
 * Two properties of one group (Sepilok and Kinabatangan), each with its owner, and a manager and a front desk user at
 * Sepilok.
 *
 * @return array{sepilok: Property, kinabatangan: Property, sepilokOwner: User, kinabatanganOwner: User, manager: User, frontDesk: User}
 */
function groupSetup(): array
{
    $group = PropertyGroup::factory()->create(['name' => 'Tanini']);
    $sepilokOwner = User::factory()->owner()->create();
    $kinabatanganOwner = User::factory()->owner()->create();
    $sepilok = $sepilokOwner->property;
    $kinabatangan = $kinabatanganOwner->property;
    $sepilok->forceFill(['property_group_id' => $group->id, 'name' => 'Tanini Sepilok'])->save();
    $kinabatangan->forceFill(['property_group_id' => $group->id, 'name' => 'Tanini Kinabatangan'])->save();

    return [
        'sepilok' => $sepilok,
        'kinabatangan' => $kinabatangan,
        'sepilokOwner' => $sepilokOwner,
        'kinabatanganOwner' => $kinabatanganOwner,
        'manager' => User::factory()->for($sepilok)->withRole(DefaultRoleEnum::Manager)->create(['name' => 'Mira']),
        'frontDesk' => User::factory()->for($sepilok)->withRole(DefaultRoleEnum::FrontDesk)->create(['name' => 'Farid']),
    ];
}

function roleAt(Property $property, DefaultRoleEnum $role): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $role)->firstOrFail();
}

/**
 * The Kinabatangan owner gives the user access there with the role.
 *
 * @param  array{kinabatangan: Property, kinabatanganOwner: User}  $setup
 */
function grantKinabatangan(array $setup, User $user, DefaultRoleEnum $role): void
{
    test()->actingAs($setup['kinabatanganOwner'])
        ->post(route('settings.staff.access.store', $setup['kinabatangan']->code), ['user_id' => $user->id, 'role_id' => roleAt($setup['kinabatangan'], $role)->id])
        ->assertSessionHasNoErrors();
}

/**
 * Both lodges live, with settings, a business date of 2026-10-14 and a room type of three rooms each.
 *
 * @return array{sepilok: Property, kinabatangan: Property, sepilokOwner: User, kinabatanganOwner: User, manager: User, frontDesk: User, roomTypes: array<string, RoomType>}
 */
function liveGroupSetup(): array
{
    $setup = groupSetup();
    $roomTypes = [];

    foreach (['sepilok', 'kinabatangan'] as $key) {
        $property = $setup[$key];
        $property->forceFill(['status' => PropertyStatus::Live])->save();
        PropertySetting::factory()->for($property)->create();
        BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
        $roomTypes[$key] = RoomType::factory()->for($property)->create();
        Room::factory()->count(3)->for($property)->create(['room_type_id' => $roomTypes[$key]->id]);
    }

    return [...$setup, 'roomTypes' => $roomTypes];
}

function groupArrival(Property $property, RoomType $roomType): ReservationUnit
{
    return ReservationUnit::factory()
        ->for(Reservation::factory()->create(['property_id' => $property->id]))
        ->withNights()
        ->create(['room_type_id' => $roomType->id, 'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
}
