<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('admin pages share the admin navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('adminNavigation', 3)
            ->where('adminNavigation.1', [
                'title' => 'Settings',
                'href' => '/admin/settings',
                'icon' => 'settings',
                'external' => false,
            ])
        );
});

test('staff pages do not share the admin navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('adminNavigation', null));
});
