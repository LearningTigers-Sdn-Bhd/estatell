<?php

use App\Enums\DefaultRoleEnum;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;

/**
 * A live property with settings and the business date 2026-10-10, and a user with the role.
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: User, 1: Property}
 */
function reportSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager, array $settings = []): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create($settings);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}
