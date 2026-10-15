<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('staff account navigation marks the current page and includes personal notifications', function (string $routeName, int $activeIndex) {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accountNavigation', 4)
            ->where('accountNavigation.0.title', 'Profile')
            ->where('accountNavigation.1.title', 'Security')
            ->where('accountNavigation.2.title', 'Appearance')
            ->where('accountNavigation.3.href', '/account/notifications')
            ->where("accountNavigation.{$activeIndex}.active", true)
            ->where('accountNavigation', fn ($items) => collect($items)->where('active', true)->count() === 1)
        );
})->with([
    ['account.profile.edit', 0],
    ['account.security.edit', 1],
    ['account.appearance.edit', 2],
    ['account.notifications.edit', 3],
]);

test('admins and partners have account navigation without staff notifications', function (string $kind) {
    $this->actingAs(User::factory()->{$kind}()->create())
        ->get(route('account.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accountNavigation', 3)
            ->where('accountNavigation.0.active', true)
            ->where('accountNavigation.2.title', 'Appearance')
        );
})->with(['admin', 'partner']);

test('support sessions keep their banner and omit security from account navigation', function () {
    $staff = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('view-as.store', $staff->property->code), ['user' => $staff->id, 'reason' => 'support'])
        ->assertRedirect();

    $this->get(route('account.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('accountNavigation', 3)
            ->where('accountNavigation.0.title', 'Profile')
            ->where('accountNavigation.1.title', 'Appearance')
            ->where('accountNavigation.2.title', 'Notifications')
            ->where('impersonation.supportUserName', $admin->name)
        );
});

test('workspace pages do not receive account navigation', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)
        ->get(route('dashboard', $staff->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('accountNavigation', null));
});
