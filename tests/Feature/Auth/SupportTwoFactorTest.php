<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['estatell.support_require_two_factor' => true]);

    Route::middleware(['web', 'auth', 'can:admin', 'support.2fa'])
        ->post('/admin/_locked-action', fn () => response('done'));
});

test('an admin without two-factor is sent back with an error toast', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.dashboard'))
        ->post('/admin/_locked-action')
        ->assertRedirect(route('admin.dashboard'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);
});

test('an admin without two-factor gets a 403 on json requests', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/admin/_locked-action')
        ->assertForbidden();
});

test('an admin with confirmed two-factor can use admin actions', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post('/admin/_locked-action')
        ->assertOk();
});

test('admin actions stay open when the setting is off', function () {
    config(['estatell.support_require_two_factor' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/_locked-action')
        ->assertOk();
});

test('the support prop describes the two-factor reminder for each user', function (Closure $user, bool $requireTwoFactor, ?array $expected) {
    config(['estatell.support_require_two_factor' => $requireTwoFactor]);

    $this->actingAs($user())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('supportTwoFactor', $expected));
})->with([
    'admin without two-factor, switch on' => [fn () => User::factory()->admin()->create(), true, ['required' => true]],
    'admin without two-factor, switch off' => [fn () => User::factory()->admin()->create(), false, ['required' => false]],
    'admin with two-factor, switch on' => [fn () => User::factory()->admin()->withTwoFactor()->create(), true, null],
    'staff without two-factor, switch on' => [fn () => User::factory()->create(), true, null],
]);
