<?php

use App\Models\User;
use Laravel\Fortify\Features;

test('admins are redirected to the admin dashboard after login', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($admin);
    $response->assertRedirect(route('admin.dashboard'));
});

test('admins are redirected to the admin dashboard after the two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    $admin = User::factory()->admin()->withTwoFactor()->create();

    $response = $this->withSession(['login.id' => $admin->id, 'login.remember' => false])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1']);

    $this->assertAuthenticatedAs($admin);
    $response->assertRedirect(route('admin.dashboard'));
});

test('signed-in admins opening the login page are sent to the admin dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('login'))
        ->assertRedirect(route('admin.dashboard'));
});

test('admins can open the admin dashboard', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertOk();
});

test('staff get a 404 on the admin dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertNotFound();
});

test('guests are sent to login from the admin dashboard', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});
