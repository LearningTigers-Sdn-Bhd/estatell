<?php

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Actions\Staff\SeedDefaultRoles;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return list<string>
 */
function roleKeysFor(User $user): array
{
    setPermissionsTeamId($user->property_id);

    return $user->unsetRelation('roles')->roles->map(fn ($role) => $role->default_role?->value)->all();
}

test('registering seeds the default roles and makes the owner the Owner role', function () {
    $owner = app(RegisterPropertyOwner::class)([
        'name' => 'Aina', 'email' => 'aina@example.com', 'password' => 'password',
        'property_name' => 'Seaview Guesthouse',
    ]);

    expect(getPermissionsTeamId())->toBeNull()
        ->and($owner->property->roles()->count())->toBe(5)
        ->and(roleKeysFor($owner))->toBe(['owner'])
        ->and($owner->can(PermissionEnum::RolesManage->value))->toBeTrue();
});

test('settings need the manage settings permission', function (DefaultRoleEnum $defaultRole, int $status) {
    $staff = User::factory()->withRole($defaultRole)->create();

    $this->actingAs($staff)->get(route('settings.edit', $staff->property->code))->assertStatus($status);
})->with([
    'owner' => [DefaultRoleEnum::Owner, 200],
    'manager' => [DefaultRoleEnum::Manager, 200],
    'front desk' => [DefaultRoleEnum::FrontDesk, 403],
]);

test('staff without the permission do not see the settings context or save settings', function () {
    $staff = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $code = $staff->property->code;

    $this->actingAs($staff)
        ->get(route('dashboard', $code))
        ->assertInertia(fn (Assert $page) => $page
            ->has('navigation.contexts', 2)
            ->where('navigation.contexts.0.key', 'front-desk')
            ->where('navigation.contexts.1.key', 'finance'));

    $this->put(route('settings.update', [$code, 'support']), ['allow_support_access' => '0'])->assertForbidden();
});

test('owners keep the settings context on account pages', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts.2.key', 'settings'));
});

test('the backfill migration makes the first user of each property without roles the Owner', function () {
    $property = Property::factory()->create();
    $first = User::factory()->for($property)->create();
    $second = User::factory()->for($property)->create();
    $seeded = Property::factory()->create();
    app(SeedDefaultRoles::class)($seeded);
    $seededUser = User::factory()->for($seeded)->create();

    (require database_path('migrations/2026_09_28_033628_assign_owner_roles_to_existing_properties.php'))->up();

    expect($property->roles()->count())->toBe(5)
        ->and(roleKeysFor($first))->toBe(['owner'])
        ->and(roleKeysFor($second))->toBe([])
        ->and(roleKeysFor($seededUser))->toBe([]);
});
