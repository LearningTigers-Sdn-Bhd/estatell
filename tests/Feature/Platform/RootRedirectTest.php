<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests opening the root URL see the landing page', function () {
    $this->withoutVite();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('landing'));

    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('the legal pages are public', function () {
    $this->withoutVite();

    $this->get(route('terms'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('legal/terms'));
    $this->get(route('privacy'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('legal/privacy'));
});

test('only the public pages can be indexed by search engines', function () {
    $this->withoutVite();

    $this->get('/')->assertDontSee('noindex', false);
    $this->get(route('privacy'))->assertDontSee('noindex', false);
    $this->get(route('login'))->assertSee('noindex', false);
});

test('signed-in users reach their home through the dashboard URL', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
});
