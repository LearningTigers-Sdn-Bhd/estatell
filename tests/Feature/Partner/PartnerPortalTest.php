<?php

use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a partner signs in to the portal and /dashboard sends them there', function () {
    $partner = User::factory()->partner()->create();

    $this->post(route('login.store'), ['email' => $partner->email, 'password' => 'password'])
        ->assertRedirect(route('partner.properties.index'));

    $this->get(route('home.redirect'))->assertRedirect(route('partner.properties.index'));
});

test('the portal shows an empty Properties page with the partner navigation', function () {
    $this->actingAs(User::factory()->partner()->create())
        ->get('/partner')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('partner/properties/index')
            ->has('partnerNavigation', 1)
            ->where('partnerNavigation.0.title', 'Properties')
            ->where('adminNavigation', null)
            ->where('navigation', null)
            ->where('property', null)
        );
});

test('a partner gets a 404 on /admin and on a property', function () {
    $partner = User::factory()->partner()->create();
    $property = Property::factory()->create(['partner_id' => $partner->partner_id]);

    $this->actingAs($partner)->get('/admin')->assertNotFound();
    $this->get(route('dashboard', $property->code))->assertNotFound();
});

test('staff and admins get a 404 on the portal', function () {
    $this->actingAs(User::factory()->create())->get('/partner')->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->get('/partner')->assertNotFound();
});

test('a guest is sent to login from the portal', function () {
    $this->get('/partner')->assertRedirect(route('login'));
});

test('a partner that is not Active gets a 404 on the portal', function () {
    $partner = User::factory()->partner(Partner::factory()->suspended()->create())->create();

    $this->actingAs($partner)->get('/partner')->assertNotFound();
});

test('a partner can open the account pages inside the partner navigation', function () {
    $this->actingAs(User::factory()->partner()->create())
        ->get(route('account.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('partnerNavigation', 1)->where('adminNavigation', null));
});

test('an expired partner temp password shows the support message', function () {
    $partner = User::factory()->partner()->withTemporaryPassword()->create();
    $partner->forceFill(['temporary_password_expires_at' => now()->subMinute()])->save();

    $this->post(route('login.store'), ['email' => $partner->email, 'password' => 'Kopi-7342-Meja'])
        ->assertSessionHasErrors(['email' => 'This temporary password has expired. Ask estatell support for a new one.']);

    $this->assertGuest();
});

test('a partner with a temp password is sent to set a password first', function () {
    $this->actingAs(User::factory()->partner()->withTemporaryPassword()->create())
        ->get('/partner')
        ->assertRedirect(route('account.set-password.edit'));
});
