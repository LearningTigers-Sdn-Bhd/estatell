<?php

use App\Models\User;

test('guests opening the root URL end up at sign-in', function () {
    $this->get('/')->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('signed-in users opening the root URL are sent to their home', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/')->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
});
