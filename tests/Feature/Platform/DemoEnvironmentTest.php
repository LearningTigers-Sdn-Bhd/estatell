<?php

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Switch the app to the demo environment. CSRF is skipped only while the environment is `testing`, so it is turned
 * off here by hand.
 */
function onDemo(): void
{
    app()['env'] = 'demo';
    test()->withoutMiddleware(PreventRequestForgery::class);
}

test('signup is refused on the demo environment', function () {
    onDemo();

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Not available in the demo']);

    $this->post(route('register.store'), [
        'name' => 'Visitor',
        'email' => 'visitor@example.com',
        'password' => 'Password-2026!',
        'password_confirmation' => 'Password-2026!',
    ])->assertRedirect(route('login'));

    expect(User::query()->where('email', 'visitor@example.com')->exists())->toBeFalse();
});

test('account security writes are refused on the demo environment', function () {
    onDemo();
    $user = User::factory()->create(['email' => 'owner@demo.test', 'password' => 'demo-pass-2026']);

    $this->actingAs($user)
        ->from(route('account.profile.edit'))
        ->patch(route('account.profile.update'), ['name' => 'Changed', 'email' => 'mine@example.com'])
        ->assertRedirect(route('account.profile.edit'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Not available in the demo']);

    $this->actingAs($user)->put(route('account.password.update'), [
        'current_password' => 'demo-pass-2026',
        'password' => 'Another-pass-2026!',
        'password_confirmation' => 'Another-pass-2026!',
    ]);
    $this->actingAs($user)->delete(route('account.profile.destroy'), ['password' => 'demo-pass-2026']);
    $this->actingAs($user)->post(route('two-factor.enable'));

    $user->refresh();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and($user->email)->toBe('owner@demo.test')
        ->and(Hash::check('demo-pass-2026', $user->password))->toBeTrue()
        ->and($user->two_factor_secret)->toBeNull();
});

test('a JSON request to a blocked route gets a 403 on the demo environment', function () {
    onDemo();

    $this->actingAs(User::factory()->create())
        ->postJson(route('two-factor.enable'))
        ->assertForbidden()
        ->assertJson(['message' => 'Not available in the demo']);
});

test('outside the demo environment the account pages work and no banner is shared', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('account.profile.update'), ['name' => 'Changed', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->name)->toBe('Changed');

    $this->actingAs($user)
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('isDemo', false));
});

test('the demo environment shares isDemo for the banner', function () {
    onDemo();

    $this->actingAs(User::factory()->create())
        ->get(route('account.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('isDemo', true));
});

test('partners see the Demo access card only when the demo link and password are set', function () {
    $user = User::factory()->partner(Partner::factory()->create())->create();

    config(['estatell.demo.url' => null, 'estatell.demo.password' => 'demo-pass-2026']);

    $this->actingAs($user)
        ->get(route('partner.properties.index'))
        ->assertInertia(fn (Assert $page) => $page->where('demoAccess', null));

    config(['estatell.demo.url' => 'https://demo.estatell.com']);

    $this->actingAs($user)
        ->get(route('partner.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('demoAccess.url', 'https://demo.estatell.com')
            ->where('demoAccess.password', 'demo-pass-2026')
            ->where('demoAccess.logins.0.email', 'pearlside@demo.estatell.com')
            ->where('demoAccess.logins.1.email', 'boreno@demo.estatell.com'));
});
