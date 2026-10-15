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

test('owners see every role as a column with its permissions', function () {
    $owner = User::factory()->owner()->create();
    User::factory()->for($owner->property)->withRole(DefaultRoleEnum::Manager)->deactivated()->create();

    $this->actingAs($owner)
        ->get(route('settings.roles.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->component('settings/roles/index')
            ->has('roles', 5)
            ->where('roles.0', [
                'id' => roleOf($owner->property, DefaultRoleEnum::Owner)->id,
                'name' => 'Owner',
                'isDefault' => true,
                'isLocked' => true,
                'staffCount' => 1,
                'permissions' => ['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.anonymize', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'notification.manage', 'payment.record', 'payment.refund', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'roles.manage', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'settings.ota-lists', 'staff.manage', 'staff.view'],
            ])
            ->where('roles.1.name', 'Manager')
            ->where('roles.1.staffCount', 0)
            ->where('roles.1.permissions', ['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'payment.record', 'payment.refund', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'staff.manage', 'staff.view'])
            ->has('permissions', 55)
            ->where('permissions.0', ['value' => 'settings.manage', 'label' => 'Manage property settings', 'group' => 'Property']));
});

test('managing roles needs roles.manage', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $code = $manager->property->code;

    $this->actingAs($manager)->get(route('settings.roles.index', $code))->assertForbidden();
    $this->post(route('settings.roles.store', $code), ['name' => 'Night audit'])->assertForbidden();
    $this->put(route('settings.roles.update', $code), ['roles' => []])->assertForbidden();
});

test('owners create a custom role that appears as a new column', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('settings.roles.store', $property->code), ['name' => ' Night manager '])
        ->assertRedirect(route('settings.roles.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Role Night manager created. Tick its permissions and save.']);

    $role = Role::query()->where('property_id', $property->id)->where('name', 'Night manager')->sole();

    expect($role->default_role)->toBeNull()
        ->and($role->permissions()->count())->toBe(0)
        ->and(Activity::query()->sole()->event)->toBe('role.created');

    $this->post(route('settings.roles.store', $property->code), ['name' => 'Manager'])->assertSessionHasErrors('name');
});

test('saving the grid updates only changed roles and gives the role holders access', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $role = Role::query()->create(['property_id' => $property->id, 'name' => 'Night manager']);
    $manager = roleOf($property, DefaultRoleEnum::Manager);
    $nightManager = User::factory()->for($property)->create();
    setPermissionsTeamId($property->id);
    $nightManager->assignRole($role);

    $this->actingAs($owner)
        ->put(route('settings.roles.update', $property->code), ['roles' => [
            $role->id => ['staff.view'],
            $manager->id => ['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'payment.record', 'payment.refund', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'staff.manage', 'staff.view'],
            roleOf($property, DefaultRoleEnum::FrontDesk)->id => ['company.view', 'folio.close', 'folio.discount', 'folio.post-charge', 'folio.print-voucher', 'front-desk.check-in', 'front-desk.check-out', 'guests.manage', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'payment.record', 'rates.view', 'registration-card.send-link', 'registration-card.sign', 'reservations.manage', 'reservations.view', 'rooms.block'],
        ]])
        ->assertRedirect(route('settings.roles.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Permissions saved for 1 role.']);

    expect($role->permissions()->pluck('name')->all())->toBe(['staff.view'])
        ->and(Activity::query()->sole()->properties->all())->toMatchArray(['old' => [], 'new' => ['staff.view']]);

    $this->actingAs($nightManager)->get(route('settings.staff.index', $property->code))->assertOk();

    $this->actingAs($owner)
        ->put(route('settings.roles.update', $property->code), ['roles' => [$role->id => [], $manager->id => []]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Permissions saved for 2 roles.']);

    expect($role->permissions()->count())->toBe(0)
        ->and($manager->permissions()->count())->toBe(0);

    $this->put(route('settings.roles.update', $property->code), ['roles' => [$role->id => []]])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No permissions changed.']);
});

test('the owner column is skipped, and unknown permissions or other properties\' roles are rejected', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $ownerRole = roleOf($property, DefaultRoleEnum::Owner);
    $other = Property::factory()->create();
    app(SeedDefaultRoles::class)($other);
    $update = route('settings.roles.update', $property->code);

    $this->actingAs($owner)->put($update, ['roles' => [$ownerRole->id => []]])
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'No permissions changed.']);
    $this->put($update, ['roles' => [roleOf($property, DefaultRoleEnum::Manager)->id => ['folio.void']]])
        ->assertSessionHasErrors('roles.'.roleOf($property, DefaultRoleEnum::Manager)->id.'.0');
    $this->put($update, ['roles' => [roleOf($other, DefaultRoleEnum::Manager)->id => []]])
        ->assertSessionHasErrors('roles');

    expect($ownerRole->permissions()->count())->toBe(55)
        ->and(roleOf($other, DefaultRoleEnum::Manager)->permissions()->count())->toBe(51)
        ->and(Activity::query()->count())->toBe(0);
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
