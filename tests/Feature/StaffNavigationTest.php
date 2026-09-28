<?php

use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('staff get front desk and settings contexts with the active context from the route', function () {
    $staff = User::factory()->create();
    $code = $staff->property->code;

    $this->actingAs($staff)
        ->get(route('dashboard', $code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'front-desk')
            ->has('navigation.contexts', 2)
            ->where('navigation.contexts.0.key', 'front-desk')
            ->where('navigation.contexts.0.href', "/p/{$code}/dashboard")
            ->where('navigation.contexts.0.groups.0.items.0.active', true)
            ->where('navigation.contexts.1.key', 'settings')
            ->where('navigation.contexts.1.groups.0.items.0.active', false)
        );
});

test('the details item stays active on every settings tab', function (string $routeName) {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route($routeName, $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', 'settings')
            ->where('navigation.contexts.0.groups.0.items.0.active', false)
            ->where('navigation.contexts.1.groups.0.items.0', [
                'title' => 'Details',
                'href' => '/p/'.$staff->property->code.'/settings',
                'icon' => 'building',
                'external' => false,
                'active' => true,
            ])
        );
})->with(['settings.edit', 'settings.operations', 'settings.registration', 'settings.support']);

test('staff keep their property navigation on account pages with no active context', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation.active', null)
            ->where('navigation.contexts.0.href', '/p/'.$staff->property->code.'/dashboard')
        );
});

test('admins and staff of suspended properties get no staff navigation', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('navigation', null));

    $staff = User::factory()->for(Property::factory()->suspended())->create();

    $this->actingAs($staff)
        ->get(route('property.suspended', $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('navigation', null));
});
