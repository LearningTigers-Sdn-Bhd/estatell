<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('admin pages share the admin navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('adminNavigation', 7)
            ->where('adminNavigation.1', [
                'title' => 'Properties',
                'href' => '/admin/properties',
                'icon' => 'building',
                'external' => false,
                'active' => false,
                'action' => null,
                'badge' => null,
            ])
        );
});

test('admins get the admin navigation on their account pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->has('adminNavigation', 7));
});

test('staff never get the admin navigation', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('adminNavigation', null));

    $this->actingAs($staff)
        ->get(route('dashboard', $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('adminNavigation', null));
});
