<?php

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

test('staff open their own property dashboard with the property prop', function () {
    $staff = User::factory()->create();
    $property = $staff->property;

    $this->actingAs($staff)
        ->get(route('dashboard', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('property', ['code' => $property->code, 'name' => $property->name, 'status' => 'onboarding'])
        );

    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($property->id);
});

test('staff get a 404 on another property', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard', Property::factory()->create()->code))
        ->assertNotFound();
});

test('an unknown property code is a 404', function () {
    $this->actingAs(User::factory()->create())
        ->get('/p/99999999/dashboard')
        ->assertNotFound();
});

test('a non-numeric property code does not match the route', function () {
    $this->actingAs(User::factory()->create())
        ->get('/p/abc/dashboard')
        ->assertNotFound();
});

test('admins get a 404 on property pages until view-as exists', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard', Property::factory()->create()->code))
        ->assertNotFound();
});

test('guests are sent to login from property pages', function () {
    $this->get(route('dashboard', Property::factory()->create()->code))
        ->assertRedirect(route('login'));
});

test('unverified staff are sent to verify their email', function () {
    $staff = User::factory()->unverified()->create();

    $this->actingAs($staff)
        ->get(route('dashboard', $staff->property->code))
        ->assertRedirect(route('verification.notice'));
});

test('the home redirect sends staff to their property and admins to admin', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('home.redirect'))
        ->assertRedirect(route('dashboard', $staff->property->code));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('home.redirect'))
        ->assertRedirect(route('admin.dashboard'));
});

test('the home redirect sends onboarding owners to Basics while other staff and live owners keep the dashboard', function () {
    $owner = User::factory()->owner()->create();
    $staff = User::factory()->for($owner->property)->create();
    $liveOwner = User::factory()->owner()->create();
    $liveOwner->property->update(['status' => PropertyStatus::Live, 'went_live_at' => now()]);

    $this->actingAs($owner)
        ->get(route('home.redirect'))
        ->assertRedirect(route('onboarding.basics.edit', $owner->property->code));

    $this->actingAs($staff)
        ->get(route('home.redirect'))
        ->assertRedirect(route('dashboard', $staff->property->code));

    $this->actingAs($liveOwner)
        ->get(route('home.redirect'))
        ->assertRedirect(route('dashboard', $liveOwner->property->code));
});

test('pages without a property in the URL share the staff user\'s property', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('property.code', $staff->property->code));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('property', null));
});
