<?php

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\Staff\TemporaryPasswordGenerator;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function temporaryPasswordRole(Property $property, DefaultRoleEnum $defaultRole): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $defaultRole)->firstOrFail();
}

test('temp passwords are two words and four digits', function () {
    $generator = app(TemporaryPasswordGenerator::class);

    foreach (range(1, 50) as $ignored) {
        expect($generator->generate())->toMatch('/^[A-Z][a-km-z]+-\d{4}-[A-Z][a-km-z]+$/');
    }
});

test('owners add staff with a temp password, verified and with the role', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('settings.staff.store', $property->code), [
            'name' => 'Ali Hassan',
            'email' => ' Ali@Hotel.com ',
            'role_id' => temporaryPasswordRole($property, DefaultRoleEnum::FrontDesk)->id,
        ])
        ->assertRedirect(route('settings.staff.index', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Ali Hassan was added.']);

    $staff = User::query()->where('email', 'ali@hotel.com')->sole();

    expect($staff->property_id)->toBe($property->id)
        ->and($staff->email_verified_at)->not->toBeNull()
        ->and($staff->must_change_password)->toBeTrue()
        ->and(Hash::check($staff->temporary_password, $staff->password))->toBeTrue()
        ->and($staff->temporary_password_expires_at->isSameDay(now()->addDays(14)))->toBeTrue();

    setPermissionsTeamId($property->id);
    expect($staff->roles->sole()->default_role)->toBe(DefaultRoleEnum::FrontDesk);

    $activity = Activity::query()->sole();
    expect($activity->event)->toBe('staff.created-with-temp-password')
        ->and(json_encode($activity->properties))->not->toContain($staff->temporary_password);
});

test('adding staff follows the invitation email and role rules', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = $manager->property;
    $taken = User::factory()->create(['email' => 'taken@hotel.com']);
    StaffInvitation::factory()->for($property)->create(['email' => 'invited@hotel.com', 'role_id' => temporaryPasswordRole($property, DefaultRoleEnum::FrontDesk)->id]);

    $this->actingAs($manager)
        ->post(route('settings.staff.store', $property->code), ['name' => '', 'email' => $taken->email, 'role_id' => temporaryPasswordRole($property, DefaultRoleEnum::Owner)->id])
        ->assertSessionHasErrors(['name', 'email' => 'This email already has an account or an open invitation.', 'role_id' => 'Choose a role you are allowed to give.']);

    $this->post(route('settings.staff.store', $property->code), ['name' => 'Mei', 'email' => 'invited@hotel.com', 'role_id' => temporaryPasswordRole($property, DefaultRoleEnum::FrontDesk)->id])
        ->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(2);
});

test('only staff.manage adds staff, and only in their own property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $otherProperty = Property::factory()->create();
    $input = ['name' => 'Mei', 'email' => 'mei@hotel.com', 'role_id' => temporaryPasswordRole($owner->property, DefaultRoleEnum::FrontDesk)->id];

    $this->actingAs($frontDesk)->post(route('settings.staff.store', $frontDesk->property->code), $input)->assertForbidden();
    $this->actingAs($owner)->post(route('settings.staff.store', $otherProperty->code), $input)->assertNotFound();

    expect(User::query()->where('email', 'mei@hotel.com')->exists())->toBeFalse();
});

test('a temp password signs in and every page goes to set password until it is changed', function () {
    $staff = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'Kopi-7342-Meja']);
    $this->assertAuthenticatedAs($staff);

    $this->get(route('dashboard', $staff->property->code))->assertRedirect(route('account.set-password.edit'));
    $this->get(route('account.profile.edit'))->assertRedirect(route('account.set-password.edit'));
    $this->get(route('account.set-password.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/set-password')->where('email', $staff->email));

    $this->put(route('account.set-password.update'), ['password' => 'my-own-password', 'password_confirmation' => 'my-own-password'])
        ->assertRedirect(route('home.redirect'));

    $staff->refresh();
    expect(Hash::check('my-own-password', $staff->password))->toBeTrue()
        ->and($staff->temporary_password)->toBeNull()
        ->and($staff->temporary_password_expires_at)->toBeNull()
        ->and($staff->must_change_password)->toBeFalse();

    $this->get(route('account.profile.edit'))->assertOk();
});

test('users who must change their password can still log out', function () {
    $staff = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();

    $this->actingAs($staff)->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

test('the set password page sends other users home', function () {
    $staff = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($staff)->get(route('account.set-password.edit'))->assertRedirect(route('home.redirect'));
    $this->put(route('account.set-password.update'), ['password' => 'my-own-password', 'password_confirmation' => 'my-own-password'])
        ->assertRedirect(route('home.redirect'));

    expect(Hash::check('password', $staff->refresh()->password))->toBeTrue();
});

test('an expired temp password cannot sign in', function () {
    $staff = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();
    $staff->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'Kopi-7342-Meja'])
        ->assertSessionHasErrors(['email' => 'This temporary password has expired. Ask your property owner for a new one.']);

    $this->assertGuest();
});

test('owners reset a staff password to a new temp password', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $staff = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($owner)
        ->post(route('settings.staff.temp-passwords.reset', $property->code), ['user_ids' => [$staff->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'New temp password for 1 staff.']);

    $staff->refresh();
    expect(Hash::check('password', $staff->password))->toBeFalse()
        ->and(Hash::check($staff->temporary_password, $staff->password))->toBeTrue()
        ->and($staff->must_change_password)->toBeTrue()
        ->and(Activity::query()->sole()->event)->toBe('staff.temp-password-reset');
});

test('reset skips yourself, owners and deactivated staff', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = $manager->property;
    $owner = User::factory()->for($property)->withRole(DefaultRoleEnum::Owner)->create();
    $deactivated = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->deactivated()->create();
    $staff = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($manager)
        ->post(route('settings.staff.temp-passwords.reset', $property->code), ['user_ids' => [$manager->id, $owner->id, $deactivated->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No temp password was reset. Owners, deactivated staff and your own account are skipped.']);

    expect(Activity::query()->count())->toBe(0)
        ->and($owner->refresh()->must_change_password)->toBeFalse();

    $this->post(route('settings.staff.temp-passwords.reset', $property->code), ['user_ids' => [$owner->id, $staff->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'New temp password for 1 staff. 1 skipped.']);
});

test('temp password routes reject staff from another property', function () {
    $owner = User::factory()->owner()->create();
    $outsider = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();

    $this->actingAs($owner)
        ->post(route('settings.staff.temp-passwords.reset', $owner->property->code), ['user_ids' => [$outsider->id]])
        ->assertSessionHasErrors('user_ids.0');
    $this->postJson(route('settings.staff.temp-passwords.message', $owner->property->code), ['user_ids' => [$outsider->id]])
        ->assertUnprocessable();

    expect($outsider->refresh()->temporary_password)->toBe('Kopi-7342-Meja');
});

test('the copy message lists unexpired temp passwords and skips the rest', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $ali = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword('Teh-0042-Roti')->create(['email' => 'ali@hotel.com']);
    $expired = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();
    $expired->forceFill(['temporary_password_expires_at' => now()->subDay()])->save();

    $response = $this->actingAs($owner)
        ->postJson(route('settings.staff.temp-passwords.message', $property->code), ['user_ids' => [$ali->id, $expired->id, $owner->id]])
        ->assertOk()
        ->assertJson(['count' => 1, 'skipped' => 2]);

    expect($response->json('text'))
        ->toContain('1. Open: '.route('login'))
        ->toContain('2. Email: ali@hotel.com')
        ->toContain('3. Temp password: Teh-0042-Roti')
        ->toContain('4. Set your own password when asked (expires ');

    $activity = Activity::query()->sole();
    expect($activity->event)->toBe('staff.temp-passwords-exported')
        ->and($activity->properties['user_ids'])->toBe([$ali->id])
        ->and($activity->properties['format'])->toBe('message')
        ->and(json_encode($activity->properties))->not->toContain('Teh-0042-Roti');
});

test('nothing is logged when no temp password can be copied', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->postJson(route('settings.staff.temp-passwords.message', $owner->property->code), ['user_ids' => [$owner->id]])
        ->assertOk()
        ->assertJson(['text' => '', 'count' => 0, 'skipped' => 1]);

    expect(Activity::query()->count())->toBe(0);
});

test('the staff page shows the temp password expiry but never the password', function () {
    $owner = User::factory()->owner()->create(['name' => 'Aina']);
    User::factory()->for($owner->property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create(['name' => 'Zara']);

    $response = $this->actingAs($owner)->get(route('settings.staff.index', $owner->property->code));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('staff.1.name', 'Zara')
        ->where('staff.1.isOwner', false)
        ->where('staff.0.isOwner', true)
        ->whereNot('staff.1.temporaryPasswordExpiresAt', null)
        ->where('staff.0.temporaryPasswordExpiresAt', null));

    expect($response->getContent())->not->toContain('Kopi-7342-Meja');
});
