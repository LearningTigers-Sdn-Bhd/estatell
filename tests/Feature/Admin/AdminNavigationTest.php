<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('admin pages share the admin navigation in groups', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('adminNavigation', 5)
            ->where('adminNavigation.0.label', 'Overview')
            ->where('adminNavigation.0.items.0.title', 'Dashboard')
            ->where('adminNavigation.1.label', 'Business')
            ->has('adminNavigation.1.items', 5)
            ->where('adminNavigation.1.items.4.title', 'Property groups')
            ->where('adminNavigation.1.items.4.href', '/admin/property-groups')
            ->where('adminNavigation.1.items.0', [
                'title' => 'Properties',
                'href' => '/admin/properties',
                'icon' => 'building',
                'external' => false,
                'active' => false,
                'action' => null,
                'badge' => null,
            ])
            ->where('adminNavigation.2.label', 'Content')
            ->has('adminNavigation.2.items', 3)
            ->where('adminNavigation.3.label', 'Integrations')
            ->where('adminNavigation.3.items.1.title', 'Webhooks')
            ->where('adminNavigation.3.items.1.href', '/admin/webhooks')
            ->where('adminNavigation.4.label', 'System')
            ->where('adminNavigation.4.items.3.title', 'Horizon')
            ->where('adminNavigation.4.items.3.external', true)
        );
});

test('admins get the admin navigation on their account pages', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->has('adminNavigation', 5));
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
