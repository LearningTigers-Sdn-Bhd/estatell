<?php

use App\Enums\DefaultRoleEnum;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('owners deactivate staff, who move to the deactivated tab', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $staff = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create(['name' => 'Mei', 'remember_token' => 'old-token']);

    $this->actingAs($owner)
        ->post(route('settings.staff.deactivate', [$property->code, $staff->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Mei was deactivated.']);

    expect($staff->refresh()->deactivated_at)->not->toBeNull()
        ->and($staff->remember_token)->not->toBe('old-token')
        ->and(Activity::query()->sole()->event)->toBe('staff.deactivated');

    $this->get(route('settings.staff.deactivated', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'deactivated')
            ->where('counts', ['active' => 1, 'invited' => 0, 'deactivated' => 1])
            ->has('staff', 1)
            ->where('staff.0.name', 'Mei')
            ->where('staff.0.role', 'Front desk'));
});

test('deactivated users cannot log in', function () {
    $staff = User::factory()->owner()->deactivated()->create();

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Your account is deactivated. Ask the property owner to reactivate it.']);

    $this->assertGuest();
});

test('a signed-in user who gets deactivated is signed out on the next request', function () {
    $staff = User::factory()->owner()->create();
    $this->actingAs($staff);
    $staff->forceFill(['deactivated_at' => now()])->save();

    $this->get(route('account.profile.edit'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('staff cannot deactivate themselves, and only owners deactivate or reactivate owners', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = $manager->property;
    $owner = User::factory()->for($property)->withRole(DefaultRoleEnum::Owner)->create(['name' => 'Aina']);
    $deactivatedOwner = User::factory()->for($property)->withRole(DefaultRoleEnum::Owner)->deactivated()->create(['name' => 'Ben']);

    $this->actingAs($manager)
        ->post(route('settings.staff.deactivate', [$property->code, $manager->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => "{$manager->name} cannot be deactivated."]);
    $this->post(route('settings.staff.deactivate', [$property->code, $owner->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Aina cannot be deactivated.']);
    $this->post(route('settings.staff.reactivate', [$property->code, $deactivatedOwner->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Ben cannot be reactivated.']);

    expect($owner->refresh()->deactivated_at)->toBeNull()
        ->and($deactivatedOwner->refresh()->deactivated_at)->not->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});

test('owners can deactivate another owner while one owner stays active', function () {
    $owner = User::factory()->owner()->create();
    $coOwner = User::factory()->for($owner->property)->withRole(DefaultRoleEnum::Owner)->create();

    $this->actingAs($owner)->post(route('settings.staff.deactivate', [$owner->property->code, $coOwner->id]));

    expect($coOwner->refresh()->deactivated_at)->not->toBeNull();
});

test('reactivating restores sign in and refuses active users', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $staff = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->deactivated()->create(['name' => 'Mei']);

    $this->actingAs($owner)
        ->post(route('settings.staff.reactivate', [$property->code, $staff->id]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Mei was reactivated.']);
    $this->post(route('settings.staff.reactivate', [$property->code, $staff->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Mei cannot be reactivated.']);

    expect($staff->refresh()->deactivated_at)->toBeNull()
        ->and(Activity::query()->pluck('event')->all())->toBe(['staff.reactivated']);
});

test('deactivating needs staff.manage and a user of the same property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $colleague = User::factory()->for($frontDesk->property)->create();
    $owner = User::factory()->owner()->create();

    $this->actingAs($frontDesk)
        ->post(route('settings.staff.deactivate', [$frontDesk->property->code, $colleague->id]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('settings.staff.deactivate', [$owner->property->code, $colleague->id]))
        ->assertNotFound();

    expect($colleague->refresh()->deactivated_at)->toBeNull();
});
