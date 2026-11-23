<?php

require_once __DIR__.'/../Support/GroupSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\PropertyGroup;
use App\Models\PropertyMembership;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingStaff;
use App\Services\Platform\Impersonation;
use App\Services\Reservations\SalesPeople;
use App\Services\Staff\PropertyStaff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('staff open only the properties they were given, even inside a group', function () {
    $setup = groupSetup();

    $this->actingAs($setup['frontDesk'])->get(route('dashboard', $setup['kinabatangan']->code))->assertNotFound();

    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(route('dashboard', $setup['sepilok']->code))->assertOk();
    $this->get(route('dashboard', $setup['kinabatangan']->code))->assertOk();
    $this->actingAs($setup['frontDesk'])->get(route('dashboard', $setup['kinabatangan']->code))->assertNotFound();

    expect(Activity::query()->where('event', 'staff.access-granted')->sole()->properties['property_id'])->toBe($setup['kinabatangan']->id);
});

test('the role is per property, so a manager at home can be front desk at the other lodge', function () {
    $setup = groupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['manager'])->get(route('settings.staff.index', $setup['sepilok']->code))->assertOk();
    $this->get(route('settings.staff.index', $setup['kinabatangan']->code))->assertForbidden();

    $this->actingAs($setup['kinabatanganOwner'])->get(route('settings.staff.index', $setup['kinabatangan']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('counts.active', 2)
            ->where('staff', fn ($staff) => collect($staff)->firstWhere('name', 'Mira')['role'] === 'Front desk'
                && collect($staff)->firstWhere('name', 'Mira')['homeProperty'] === 'Tanini Sepilok'
                && collect($staff)->firstWhere('name', 'Mira')['temporaryPasswordExpiresAt'] === null));
});

test('only staff of another property in the same group can be given access, with a role the actor may give', function () {
    $setup = groupSetup();
    $outsider = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $url = route('settings.staff.access.store', $setup['kinabatangan']->code);
    $manager = User::factory()->for($setup['kinabatangan'])->withRole(DefaultRoleEnum::Manager)->create();

    $this->actingAs($setup['kinabatanganOwner'])
        ->post($url, ['user_id' => $outsider->id, 'role_id' => roleAt($setup['kinabatangan'], DefaultRoleEnum::Manager)->id])
        ->assertSessionHasErrors(['user_id' => 'Choose a staff member of another property in this group.']);
    $this->post($url, ['user_id' => $manager->id, 'role_id' => roleAt($setup['kinabatangan'], DefaultRoleEnum::Manager)->id])
        ->assertSessionHasErrors('user_id');
    $this->actingAs($manager)
        ->post($url, ['user_id' => $setup['frontDesk']->id, 'role_id' => roleAt($setup['kinabatangan'], DefaultRoleEnum::Owner)->id])
        ->assertSessionHasErrors(['role_id' => 'Choose a role you are allowed to give.']);
    $this->actingAs($setup['frontDesk'])
        ->post(route('settings.staff.access.store', $setup['sepilok']->code), ['user_id' => $setup['frontDesk']->id, 'role_id' => roleAt($setup['sepilok'], DefaultRoleEnum::Manager)->id])
        ->assertForbidden();

    $setup['kinabatangan']->forceFill(['property_group_id' => null])->save();
    $this->actingAs($setup['kinabatanganOwner'])->get(route('settings.staff.index', $setup['kinabatangan']->code))
        ->assertInertia(fn (Assert $page) => $page->where('colleagues', []));

    expect(PropertyMembership::query()->where('property_id', $setup['kinabatangan']->id)->count())->toBe(2);
});

test('deactivating at one property keeps the other; with none left, sign-in stops', function () {
    $setup = groupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['kinabatanganOwner'])
        ->post(route('settings.staff.deactivate', [$setup['kinabatangan']->code, $setup['manager']->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Mira was deactivated.']);

    expect($setup['manager']->refresh()->deactivated_at)->toBeNull();
    $this->actingAs($setup['manager'])->get(route('dashboard', $setup['kinabatangan']->code))->assertNotFound();
    $this->get(route('dashboard', $setup['sepilok']->code))->assertOk();

    $this->actingAs($setup['sepilokOwner'])->post(route('settings.staff.deactivate', [$setup['sepilok']->code, $setup['manager']->id]));

    expect($setup['manager']->refresh()->deactivated_at)->not->toBeNull();

    $this->actingAs($setup['kinabatanganOwner'])
        ->post(route('settings.staff.reactivate', [$setup['kinabatangan']->code, $setup['manager']->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Mira was reactivated.']);

    expect($setup['manager']->refresh()->deactivated_at)->toBeNull();
    $this->actingAs($setup['manager'])->get(route('dashboard', $setup['kinabatangan']->code))->assertOk();
});

test('the switcher lists the other properties, and sign-in lands on the one opened last', function () {
    $setup = groupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);

    $this->actingAs($setup['manager'])->get(route('dashboard', $setup['kinabatangan']->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('property.code', $setup['kinabatangan']->code)
            ->has('propertySwitcher', 1)
            ->where('propertySwitcher.0.code', $setup['sepilok']->code));

    expect($setup['manager']->refresh()->last_property_id)->toBe($setup['kinabatangan']->id);
    $this->get(route('home.redirect'))->assertRedirect(route('dashboard', $setup['kinabatangan']->code));

    $this->actingAs($setup['frontDesk'])->get(route('dashboard', $setup['sepilok']->code))
        ->assertInertia(fn (Assert $page) => $page->where('propertySwitcher', []));
});

test('staff lists and notifications of a property include its members from the group and leave out deactivated ones', function () {
    $setup = groupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $kinabatangan = $setup['kinabatangan'];

    setPermissionsTeamId($kinabatangan->id);
    $housekeepers = collect(app(HousekeepingStaff::class)->for($kinabatangan))->pluck('name')->all();
    $salesPeople = app(SalesPeople::class)->isAvailable($kinabatangan, $setup['manager']->id);
    $notified = app(PropertyStaff::class)->withPermission($kinabatangan->id, PermissionEnum::ReservationsManage->value)->pluck('name')->all();

    expect($housekeepers)->toContain('Mira')
        ->and($salesPeople)->toBeTrue()
        ->and($notified)->toContain('Mira')
        ->and(app(SalesPeople::class)->isAvailable($kinabatangan, $setup['frontDesk']->id))->toBeFalse();

    PropertyMembership::query()->where('property_id', $kinabatangan->id)->where('user_id', $setup['manager']->id)->update(['deactivated_at' => now()]);

    expect(app(PropertyStaff::class)->withPermission($kinabatangan->id, PermissionEnum::ReservationsManage->value)->pluck('name')->all())->not->toContain('Mira')
        ->and(app(SalesPeople::class)->isAvailable($kinabatangan, $setup['manager']->id))->toBeFalse();
});

test('only the home property resets a password', function () {
    $setup = groupSetup();
    grantKinabatangan($setup, $setup['frontDesk'], DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['kinabatanganOwner'])
        ->post(route('settings.staff.temp-passwords.reset', $setup['kinabatangan']->code), ['user_ids' => [$setup['frontDesk']->id]])
        ->assertSessionHasErrors('user_ids.0');
});

test('admins create, rename and fill groups, and cannot take out a property whose staff are shared', function () {
    $setup = groupSetup();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $loner = User::factory()->owner()->create()->property;

    $this->actingAs($setup['sepilokOwner'])->get(route('admin.property-groups.index'))->assertNotFound();

    $this->actingAs($admin)->withSession(['auth.two_factor_confirmed_at' => now()->getTimestamp()]);
    $this->get(route('admin.property-groups.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/property-groups/index')
            ->has('groups', 1)
            ->has('groups.0.properties', 2)
            ->where('ungrouped', fn ($options) => collect($options)->pluck('value')->contains($loner->code)));

    $this->post(route('admin.property-groups.store'), ['name' => 'tanini'])->assertSessionHasErrors(['name' => 'Another group already has this name.']);
    $this->post(route('admin.property-groups.store'), ['name' => 'Borneo Lodges'])->assertSessionHasNoErrors();
    $borneo = PropertyGroup::query()->where('name', 'Borneo Lodges')->sole();
    $this->put(route('admin.property-groups.update', $borneo->id), ['name' => 'Borneo Lodges Group'])->assertSessionHasNoErrors();
    $this->post(route('admin.property-groups.properties.store', $borneo->id), ['property' => $loner->code])->assertInertiaFlash('toast.type', 'success');
    $this->post(route('admin.property-groups.properties.store', $borneo->id), ['property' => $setup['sepilok']->code])->assertInertiaFlash('toast.type', 'error');

    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $this->actingAs($admin)
        ->delete(route('admin.property-groups.properties.destroy', [PropertyGroup::query()->where('name', 'Tanini')->sole()->id, $setup['kinabatangan']->code]))
        ->assertInertiaFlash('toast.type', 'error');

    expect($borneo->refresh()->name)->toBe('Borneo Lodges Group')
        ->and($loner->refresh()->property_group_id)->toBe($borneo->id)
        ->and($setup['kinabatangan']->refresh()->property_group_id)->not->toBeNull();
});

test('the access check and the switcher cost the same queries however many properties a user has', function () {
    $setup = groupSetup();
    $count = function () use ($setup): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($setup['manager'])->get(route('dashboard', $setup['sepilok']->code))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    Model::preventLazyLoading();

    try {
        $count();
        $one = $count();

        foreach (range(1, 3) as $ignored) {
            $other = User::factory()->owner()->create()->property;
            $other->forceFill(['property_group_id' => $setup['sepilok']->property_group_id])->save();
            $setup['manager']->memberships()->create(['property_id' => $other->id]);
        }

        // Creating properties seeds their roles, which clears the permission cache: warm it again first.
        $count();
        $four = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($four)->toBe($one);
});

test('a support session covers its own property; the menu offers the others, which open through the view-as switch', function () {
    $setup = groupSetup();
    grantKinabatangan($setup, $setup['manager'], DefaultRoleEnum::Manager);
    $admin = User::factory()->admin()->create();

    $this->actingAs($setup['manager'])->withSession([Impersonation::SESSION_KEY => [
        'support_user_id' => $admin->id,
        'user_id' => $setup['manager']->id,
        'property_id' => $setup['sepilok']->id,
        'reason' => 'support',
        'note' => null,
        'started_at' => now()->toIso8601String(),
        'return_url' => null,
    ]]);

    $this->get(route('dashboard', $setup['sepilok']->code))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('propertySwitcher', 1)
            ->where('propertySwitcher.0.code', $setup['kinabatangan']->code)
            ->where('groupSwitcher.0.name', 'Tanini'));
    $this->get(route('dashboard', $setup['kinabatangan']->code))->assertRedirect(route('view-as.show', ['property' => $setup['kinabatangan']->code, 'to' => "/p/{$setup['kinabatangan']->code}/dashboard"]));
    $this->post(route('settings.staff.access.store', $setup['kinabatangan']->code), ['user_id' => $setup['frontDesk']->id, 'role_id' => roleAt($setup['kinabatangan'], DefaultRoleEnum::FrontDesk)->id]);

    expect(PropertyMembership::query()->where('property_id', $setup['kinabatangan']->id)->where('user_id', $setup['frontDesk']->id)->exists())->toBeFalse();
});
