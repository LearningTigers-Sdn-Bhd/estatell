<?php

use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function roleOf(Property $property, DefaultRoleEnum $defaultRole): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $defaultRole)->firstOrFail();
}

/**
 * @return list<string>
 */
function currentRoleNames(User $user): array
{
    setPermissionsTeamId($user->property_id);

    return $user->refresh()->roles->pluck('name')->all();
}

test('owners see the roles with active staff and permission counts', function () {
    $owner = User::factory()->owner()->create();
    User::factory()->for($owner->property)->withRole(DefaultRoleEnum::Manager)->deactivated()->create();

    $this->actingAs($owner)
        ->get(route('settings.roles.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/roles/index')
            ->has('roles', 5)
            ->where('roles.0', ['id' => roleOf($owner->property, DefaultRoleEnum::Owner)->id, 'name' => 'Owner', 'isDefault' => true, 'isLocked' => true, 'staffCount' => 1, 'permissionCount' => 4])
            ->where('roles.1.name', 'Manager')
            ->where('roles.1.staffCount', 0)
            ->where('roles.1.permissionCount', 3));
});

test('managing roles needs roles.manage', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();

    $this->actingAs($manager)->get(route('settings.roles.index', $manager->property->code))->assertForbidden();
    $this->post(route('settings.roles.store', $manager->property->code), ['name' => 'Night audit'])->assertForbidden();
});

test('owners create a custom role and land on its page', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('settings.roles.store', $property->code), ['name' => ' Night manager '])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Role Night manager created. Choose its permissions.']);

    $role = Role::query()->where('property_id', $property->id)->where('name', 'Night manager')->sole();

    expect($role->default_role)->toBeNull()
        ->and($role->permissions()->count())->toBe(0)
        ->and(Activity::query()->sole()->event)->toBe('role.created');

    $this->post(route('settings.roles.store', $property->code), ['name' => 'Manager'])->assertSessionHasErrors('name');
});

test('saving permissions replaces them and gives the role holders access', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Night manager']);
    $nightManager = User::factory()->for($property)->create();
    setPermissionsTeamId($property->id);
    $nightManager->assignRole($role);

    $this->actingAs($owner)
        ->put(route('settings.roles.update', [$property->code, $role->id]), ['permissions' => ['staff.view']])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Permissions for Night manager saved.']);

    expect($role->permissions()->pluck('name')->all())->toBe(['staff.view'])
        ->and(Activity::query()->sole()->properties->all())->toMatchArray(['old' => [], 'new' => ['staff.view']]);

    $this->actingAs($nightManager)->get(route('settings.staff.index', $property->code))->assertOk();

    $this->actingAs($owner)->put(route('settings.roles.update', [$property->code, $role->id]), []);

    expect($role->permissions()->count())->toBe(0);
});

test('the owner role is locked and unknown permissions are rejected', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $ownerRole = roleOf($property, DefaultRoleEnum::Owner);

    $this->actingAs($owner)
        ->put(route('settings.roles.update', [$property->code, $ownerRole->id]), ['permissions' => []])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The Owner role always has every permission.']);
    $this->put(route('settings.roles.update', [$property->code, roleOf($property, DefaultRoleEnum::Manager)->id]), ['permissions' => ['folio.void']])
        ->assertSessionHasErrors('permissions.0');

    expect($ownerRole->permissions()->count())->toBe(4)
        ->and(Activity::query()->count())->toBe(0);
});

test('another property\'s role returns 404', function () {
    $owner = User::factory()->owner()->create();
    $other = Property::factory()->create();
    app(SeedDefaultRoles::class)($other);

    $this->actingAs($owner)
        ->get(route('settings.roles.edit', [$owner->property->code, roleOf($other, DefaultRoleEnum::Manager)->id]))
        ->assertNotFound();
});

test('owners change a staff member\'s role', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $staff = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create(['name' => 'Mei']);

    $this->actingAs($owner)
        ->put(route('settings.staff.role.update', [$property->code, $staff->id]), ['role_id' => roleOf($property, DefaultRoleEnum::Manager)->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Mei is now Manager.']);

    expect(currentRoleNames($staff))->toBe(['Manager'])
        ->and(Activity::query()->sole()->properties->all())->toMatchArray(['old' => 'Front desk', 'new' => 'Manager']);
});

test('role changes are refused for yourself, for owners by non-owners, and for roles the actor cannot give', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = $manager->property;
    $owner = User::factory()->for($property)->withRole(DefaultRoleEnum::Owner)->create(['name' => 'Aina']);
    $staff = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create();
    $other = Property::factory()->create();
    app(SeedDefaultRoles::class)($other);
    $update = fn (User $user) => route('settings.staff.role.update', [$property->code, $user->id]);

    $this->actingAs($manager)->put($update($manager), ['role_id' => roleOf($property, DefaultRoleEnum::FrontDesk)->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => "The role of {$manager->name} cannot be changed."]);
    $this->put($update($owner), ['role_id' => roleOf($property, DefaultRoleEnum::FrontDesk)->id])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The role of Aina cannot be changed.']);
    $this->put($update($staff), ['role_id' => roleOf($property, DefaultRoleEnum::Owner)->id])->assertSessionHasErrors('role_id');
    $this->put($update($staff), ['role_id' => roleOf($other, DefaultRoleEnum::Manager)->id])->assertSessionHasErrors('role_id');

    expect(currentRoleNames($owner))->toBe(['Owner'])
        ->and(currentRoleNames($staff))->toBe(['Front desk'])
        ->and(Activity::query()->count())->toBe(0);
});

test('owners can hand the owner role over and step down while another owner stays', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $coOwner = User::factory()->for($property)->withRole(DefaultRoleEnum::Owner)->create();

    $this->actingAs($owner)->put(route('settings.staff.role.update', [$property->code, $coOwner->id]), ['role_id' => roleOf($property, DefaultRoleEnum::Manager)->id]);

    expect(currentRoleNames($coOwner))->toBe(['Manager']);
});
