<?php

use App\Actions\Staff\SeedDefaultRoles;
use App\Actions\Staff\SyncPermissions;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Role;
use Spatie\Permission\Models\Permission;

function defaultRole(Property $property, DefaultRoleEnum $defaultRole): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $defaultRole)->firstOrFail();
}

/**
 * @return list<string>
 */
function roleNames(Role $role): array
{
    return $role->permissions()->orderBy('name')->pluck('name')->all();
}

test('migrations create a permission row for every permission case', function () {
    expect(Permission::query()->orderBy('name')->pluck('name')->all())
        ->toEqualCanonicalizing(array_map(fn (PermissionEnum $permission) => $permission->value, PermissionEnum::cases()));
});

test('seeding creates the default roles with their default permissions', function () {
    $property = Property::factory()->create();

    app(SeedDefaultRoles::class)($property);

    expect(Role::query()->where('property_id', $property->id)->count())->toBe(5)
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Owner)))->toEqualCanonicalizing(['guests.blacklist', 'guests.manage', 'guests.view', 'guests.view-documents', 'notification.manage', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'roles.manage', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'staff.manage', 'staff.view'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Manager)))->toEqualCanonicalizing(['guests.blacklist', 'guests.manage', 'guests.view', 'guests.view-documents', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'staff.manage', 'staff.view'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::FrontDesk)))->toBe(['guests.manage', 'guests.view', 'guests.view-documents', 'rates.view', 'rooms.block'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Housekeeping)))->toBe(['rates.view'])
        ->and(defaultRole($property, DefaultRoleEnum::FrontDesk)->name)->toBe('Front desk');
});

test('each property gets its own roles', function () {
    [$first, $second] = Property::factory()->count(2)->create();

    app(SeedDefaultRoles::class)($first);
    app(SeedDefaultRoles::class)($second);

    expect(defaultRole($first, DefaultRoleEnum::Owner)->id)->not->toBe(defaultRole($second, DefaultRoleEnum::Owner)->id);
});

test('seeding again keeps the roles and the owner edits', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    defaultRole($property, DefaultRoleEnum::Manager)->revokePermissionTo(PermissionEnum::SettingsManage->value);

    app(SeedDefaultRoles::class)($property);

    expect(Role::query()->where('property_id', $property->id)->count())->toBe(5)
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Manager)))->toEqualCanonicalizing(['guests.blacklist', 'guests.manage', 'guests.view', 'guests.view-documents', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'staff.manage', 'staff.view']);
});

test('syncing grants only new permissions to default roles and never to custom roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $custom = Role::query()->create(['property_id' => $property->id, 'name' => 'Night manager']);
    defaultRole($property, DefaultRoleEnum::Manager)->revokePermissionTo(PermissionEnum::SettingsManage->value);
    Permission::query()->where('name', PermissionEnum::StaffManage->value)->delete();

    app(SyncPermissions::class)();
    app(SyncPermissions::class)();

    expect(Permission::query()->where('name', PermissionEnum::StaffManage->value)->count())->toBe(1)
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Owner)))->toContain('staff.manage')
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Manager)))->toEqualCanonicalizing(['guests.blacklist', 'guests.manage', 'guests.view', 'guests.view-documents', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'staff.manage', 'staff.view'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::FrontDesk)))->toBe(['guests.manage', 'guests.view', 'guests.view-documents', 'rates.view', 'rooms.block'])
        ->and(roleNames($custom))->toBe([]);
});
