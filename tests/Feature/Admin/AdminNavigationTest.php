<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('admin pages share the admin navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('adminNavigation', 4)
            ->where('adminNavigation.1', [
                'title' => 'Properties',
                'href' => '/admin/properties',
                'icon' => 'building',
                'external' => false,
            ])
        );
});

test('non-admin pages do not share the admin navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('adminNavigation', null));
});
