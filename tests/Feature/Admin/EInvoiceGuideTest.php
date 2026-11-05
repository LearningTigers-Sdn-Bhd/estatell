<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('admins can view the e-invoice guide', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.e-invoice-guide'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/e-invoice-guide')
        );
});

test('staff users cannot access the e-invoice guide', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('admin.e-invoice-guide'))
        ->assertNotFound();
});

test('unauthenticated users are redirected to login', function () {
    $this->get(route('admin.e-invoice-guide'))
        ->assertRedirect(route('login'));
});
