<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['estatell.admin_require_two_factor' => true]);

    Route::middleware(['web', 'auth', 'can:admin', 'admin.2fa'])
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
    config(['estatell.admin_require_two_factor' => false]);

    $this->actingAs(User::factory()->admin()->create())
        ->post('/admin/_locked-action')
        ->assertOk();
});

test('the banner prop is shared only with admins who still need two-factor', function (Closure $user, bool $needsTwoFactor) {
    $this->actingAs($user())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('adminNeedsTwoFactor', $needsTwoFactor));
})->with([
    'admin without two-factor' => [fn () => User::factory()->admin()->create(), true],
    'admin with two-factor' => [fn () => User::factory()->admin()->withTwoFactor()->create(), false],
    'staff without two-factor' => [fn () => User::factory()->create(), false],
]);
