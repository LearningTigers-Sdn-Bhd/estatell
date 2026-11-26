<?php

use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Support/PosTerminalSetup.php';

beforeEach(fn () => Cache::flush());

function posTerminalSession(array $extra = []): array
{
    return ['pos_terminal_locked' => true] + $extra;
}

it('locks the browser session to /pos and blocks the back office until the password is entered again', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['manager'])->get(route('dashboard', $code))->assertOk();

    $this->actingAs($setup['manager'])->post(route('pos.terminal-mode.enter', $code))->assertRedirect();

    foreach (['dashboard', 'settings.pos-outlets.index'] as $name) {
        $this->actingAs($setup['manager'])->withSession(posTerminalSession())->get(route($name, $code))->assertForbidden();
    }

    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->get(route('account.profile.edit'))->assertForbidden();
    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->post(route('settings.pos.devices.store', $code), ['name' => 'Till', 'pos_outlet_id' => $setup['outlet']->id])->assertForbidden();
});

it('links the locked error page to the POS, with or without a property in the URL', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;

    foreach ([route('dashboard', $code), route('home.redirect'), route('account.profile.edit')] as $url) {
        $this->actingAs($setup['manager'])->withSession(posTerminalSession())->get($url)
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page->component('errors')->where('leaveTerminalUrl', "/p/{$code}/pos"));
    }
});

it('offers the way out when a locked browser without a registered till submits a PIN', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['manager'])->withSession(posTerminalSession())
        ->post(route('pos.pin.switch', $code), ['user_id' => $setup['cashier']->id, 'pin' => '1234'])
        ->assertForbidden()
        ->assertInertia(fn ($page) => $page->component('errors')->where('leaveTerminalUrl', "/p/{$code}/pos"));
});

it('keeps the launcher open for a PIN actor without pos.sell, so the terminal can still be left', function () {
    $setup = nativePosSetup();
    $kitchen = User::factory()->for($setup['property'])->withPermissions(['pos.kds'])->create();
    PosStaffPin::factory()->withPin('123456')->create(['property_id' => $setup['property']->id, 'user_id' => $kitchen->id]);

    $this->actingAs($setup['manager'])->withSession(posTerminalSession(['pos_active_actor_id' => $kitchen->id]))
        ->get(route('pos.index', $setup['property']->code))
        ->assertOk();
});

it('lets a user without pos.sell not enter Terminal Mode', function () {
    $setup = nativePosSetup();
    $clerk = User::factory()->for($setup['property'])->withPermissions(['guests.view'])->create();

    $this->actingAs($clerk)->post(route('pos.terminal-mode.enter', $setup['property']->code))->assertForbidden();
});

it('sets the session lock when Terminal Mode starts', function () {
    $setup = nativePosSetup();

    $this->actingAs($setup['manager'])->withSession(['pos_active_actor_id' => 99])->post(route('pos.terminal-mode.enter', $setup['property']->code));

    expect(session('pos_terminal_locked'))->toBeTrue()->and(session()->has('pos_active_actor_id'))->toBeFalse();
});

it('leaves Terminal Mode only with the right password', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;

    $wrong = $this->actingAs($setup['manager'])->withSession(posTerminalSession(['pos_active_actor_id' => $setup['cashier']->id]))->post(route('pos.terminal-mode.exit', $code), ['password' => 'nope']);
    expect($wrong->getSession()->get('inertia.flash_data.toast.type'))->toBe('error')
        ->and(session('pos_terminal_locked'))->toBeTrue();

    $this->actingAs($setup['manager'])->withSession(posTerminalSession(['pos_active_actor_id' => $setup['cashier']->id]))->post(route('pos.terminal-mode.exit', $code), ['password' => 'password'])->assertRedirect(route('dashboard', $code));

    expect(session()->has('pos_terminal_locked'))->toBeFalse()->and(session()->has('pos_active_actor_id'))->toBeFalse();
    $this->actingAs($setup['manager'])->get(route('dashboard', $code))->assertOk();
});

it('switches the active actor by PIN on a registered device in Terminal Mode', function () {
    $setup = nativePosSetup();
    $url = route('pos.pin.switch', $setup['property']->code);

    $response = $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $setup['token'])
        ->post($url, ['user_id' => $setup['cashier']->id, 'pin' => '1234']);

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('success')
        ->and(session('pos_active_actor_id'))->toBe($setup['cashier']->id);
});

it('keeps the actor and shows an error toast on a wrong PIN', function () {
    $setup = nativePosSetup();

    $response = $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $setup['token'])
        ->post(route('pos.pin.switch', $setup['property']->code), ['user_id' => $setup['cashier']->id, 'pin' => '0000']);

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('error')
        ->and(session()->has('pos_active_actor_id'))->toBeFalse();
});

it('refuses PIN routes from a browser without a registered device cookie', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();
    $url = route('pos.pin.switch', $setup['property']->code);
    $body = ['user_id' => $setup['cashier']->id, 'pin' => '1234'];

    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->post($url, $body)->assertForbidden();
    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, 'forged')->post($url, $body)->assertForbidden();
    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $other['token'])->post($url, $body)->assertForbidden();

    $setup['device']->forceFill(['revoked_at' => now()])->save();
    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $setup['token'])->post($url, $body)->assertForbidden();
});

it('refuses a PIN switch outside Terminal Mode', function () {
    $setup = nativePosSetup();

    $response = $this->actingAs($setup['manager'])->withSession(['pos_terminal_unlocked' => true])->withCookie(PosTerminalDevice::COOKIE, $setup['token'])
        ->post(route('pos.pin.switch', $setup['property']->code), ['user_id' => $setup['cashier']->id, 'pin' => '1234']);

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('error')
        ->and(session()->has('pos_active_actor_id'))->toBeFalse();
});

it('does not switch to a user of another property', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();

    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $setup['token'])
        ->post(route('pos.pin.switch', $setup['property']->code), ['user_id' => $other['cashier']->id, 'pin' => '1234']);

    expect(session()->has('pos_active_actor_id'))->toBeFalse();
});

it('locks a user per device after 5 wrong PINs on the route', function () {
    $setup = nativePosSetup();
    $url = route('pos.pin.switch', $setup['property']->code);

    foreach (range(1, 5) as $_) {
        $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $setup['token'])->post($url, ['user_id' => $setup['cashier']->id, 'pin' => '0000']);
    }

    $this->actingAs($setup['manager'])->withSession(posTerminalSession())->withCookie(PosTerminalDevice::COOKIE, $setup['token'])->post($url, ['user_id' => $setup['cashier']->id, 'pin' => '1234']);

    expect(session()->has('pos_active_actor_id'))->toBeFalse();
});

/**
 * Registers throwaway routes behind the real `pos.actor` and `pos.can` middleware, as the Phase 1 POS routes will use them.
 */
function posProbeRoutes(): void
{
    Route::middleware(['web', 'auth', 'property.access', 'pos', 'pos.actor'])->prefix('p/{property:code}/pos-probe')->name('pos.probe.')->group(function () {
        Route::get('sell', fn () => 'sold')->middleware('pos.can:pos.sell')->name('sell');
        Route::get('refund', fn () => 'refunded')->middleware('pos.can:pos.refund')->name('refund');
    });

    app('router')->getRoutes()->refreshNameLookups();
}

it('authorizes /pos routes against the PIN actor, never the manager browser session', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;
    posProbeRoutes();
    $withTerminal = fn (array $session) => $this->actingAs($setup['manager'])->withSession(posTerminalSession($session));

    $withTerminal([])->get(route('pos.probe.sell', $code))->assertForbidden();

    $withTerminal(['pos_active_actor_id' => $setup['cashier']->id])->get(route('pos.probe.sell', $code))->assertOk();
    $withTerminal(['pos_active_actor_id' => $setup['cashier']->id])->get(route('pos.probe.refund', $code))->assertForbidden();

    $withTerminal(['pos_active_actor_id' => $setup['manager']->id])->get(route('pos.probe.refund', $code))->assertOk();
});

it('lets a desk user outside Terminal Mode act as themselves', function () {
    $setup = nativePosSetup();
    posProbeRoutes();

    $this->actingAs($setup['cashier'])->get(route('pos.probe.sell', $setup['property']->code))->assertOk();
    $this->actingAs($setup['cashier'])->get(route('pos.probe.refund', $setup['property']->code))->assertForbidden();
});

it('drops the actor when their PIN is revoked, their account is deactivated or they belong to another property', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();
    $code = $setup['property']->code;
    posProbeRoutes();
    $as = fn (int $id) => $this->actingAs($setup['manager'])->withSession(posTerminalSession(['pos_active_actor_id' => $id]))->get(route('pos.probe.sell', $code));

    $as($setup['cashier']->id)->assertOk();

    $as($other['cashier']->id)->assertForbidden();

    $setup['cashier']->forceFill(['deactivated_at' => now()])->save();
    $as($setup['cashier']->id)->assertForbidden();

    $setup['cashier']->forceFill(['deactivated_at' => null])->save();
    PosStaffPin::query()->where('user_id', $setup['cashier']->id)->delete();
    $as($setup['cashier']->id)->assertForbidden();
});

it('keeps a registered device locked after the session is lost, until a password-confirmed exit', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;
    posProbeRoutes();
    $device = fn () => $this->actingAs($setup['manager'])->withCookie(PosTerminalDevice::COOKIE, $setup['token']);

    // A fresh session on a registered device (an expired session, a remember-me login): no lock flag in it.
    $device()->get(route('dashboard', $code))->assertForbidden();
    $device()->get(route('settings.pos-outlets.index', $code))->assertForbidden();

    // The manager's own permissions do not leak to the till: no PIN actor yet.
    $device()->get(route('pos.probe.sell', $code))->assertForbidden();

    $device()->post(route('pos.terminal-mode.exit', $code), ['password' => 'nope']);
    $device()->get(route('dashboard', $code))->assertForbidden();

    $device()->post(route('pos.terminal-mode.exit', $code), ['password' => 'password'])->assertRedirect(route('dashboard', $code));
    expect(session('pos_terminal_unlocked'))->toBeTrue();

    $this->actingAs($setup['manager'])->withSession(['pos_terminal_unlocked' => true])->withCookie(PosTerminalDevice::COOKIE, $setup['token'])->get(route('dashboard', $code))->assertOk();
});

it('lets the PIN switch work on a registered device with no lock flag in the session', function () {
    $setup = nativePosSetup();

    $this->actingAs($setup['manager'])->withCookie(PosTerminalDevice::COOKIE, $setup['token'])
        ->post(route('pos.pin.switch', $setup['property']->code), ['user_id' => $setup['cashier']->id, 'pin' => '1234']);

    expect(session('pos_active_actor_id'))->toBe($setup['cashier']->id);
});

it('stops locking a browser once its device is revoked', function () {
    $setup = nativePosSetup();
    $setup['device']->forceFill(['revoked_at' => now()])->save();

    $this->actingAs($setup['manager'])->withCookie(PosTerminalDevice::COOKIE, $setup['token'])->get(route('dashboard', $setup['property']->code))->assertOk();
});

it('starts Terminal Mode again after a password-confirmed unlock', function () {
    $setup = nativePosSetup();

    $this->actingAs($setup['manager'])->withSession(['pos_terminal_unlocked' => true])->post(route('pos.terminal-mode.enter', $setup['property']->code));

    expect(session('pos_terminal_locked'))->toBeTrue()->and(session()->has('pos_terminal_unlocked'))->toBeFalse();
});
