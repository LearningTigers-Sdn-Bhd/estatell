<?php

use App\Models\PosStaffPin;
use App\Models\PosTerminalDevice;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/NativePosSetup.php';

it('registers the browser as a terminal device with a signed HttpOnly cookie and stores only the hash', function () {
    $setup = nativePosSetup();

    $response = $this->actingAs($setup['manager'])->post(route('settings.pos.devices.store', $setup['property']->code), ['name' => 'Bar tablet', 'pos_outlet_id' => $setup['outlet']->id]);

    $device = PosTerminalDevice::query()->where('name', 'Bar tablet')->sole();
    $cookie = $response->getCookie(PosTerminalDevice::COOKIE, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($device->property_id)->toBe($setup['property']->id)
        ->and($device->pos_outlet_id)->toBe($setup['outlet']->id)
        ->and($device->device_token_hash)->toHaveLength(64)
        ->and(Activity::query()->where('event', 'pos.device_registered')->count())->toBe(1);

    // The cookie value is encrypted, so it never equals the stored hash or the plain token.
    expect($cookie->getValue())->not->toBe($device->device_token_hash);
});

it('refuses to register a device for another property outlet', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();

    $response = $this->actingAs($setup['manager'])->post(route('settings.pos.devices.store', $setup['property']->code), ['name' => 'Sneaky', 'pos_outlet_id' => $other['outlet']->id]);

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('error')
        ->and(PosTerminalDevice::query()->where('name', 'Sneaky')->exists())->toBeFalse()
        ->and($response->getCookie(PosTerminalDevice::COOKIE, false))->toBeNull();
});

it('needs pos.manage to register or revoke a device and to clear a PIN', function () {
    $setup = nativePosSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['cashier'])->post(route('settings.pos.devices.store', $code), ['name' => 'Till', 'pos_outlet_id' => $setup['outlet']->id])->assertForbidden();
    $this->actingAs($setup['cashier'])->post(route('settings.pos.devices.revoke', [$code, $setup['device']->id]))->assertForbidden();
    $this->actingAs($setup['cashier'])->post(route('settings.pos.staff-pins.revoke', [$code, $setup['manager']->id]))->assertForbidden();
});

it('revokes a device so its cookie stops working', function () {
    $setup = nativePosSetup();

    $response = $this->actingAs($setup['manager'])->post(route('settings.pos.devices.revoke', [$setup['property']->code, $setup['device']->id]));

    expect($response->getSession()->get('inertia.flash_data.toast.type'))->toBe('success')
        ->and($setup['device']->fresh()->revoked_at)->not->toBeNull();

    $again = $this->actingAs($setup['manager'])->post(route('settings.pos.devices.revoke', [$setup['property']->code, $setup['device']->id]));
    expect($again->getSession()->get('inertia.flash_data.toast.type'))->toBe('error');
});

it('answers 404 for a device or user of another property', function () {
    $setup = nativePosSetup();
    $other = nativePosSetup();
    $code = $setup['property']->code;

    $this->actingAs($setup['manager'])->post(route('settings.pos.devices.revoke', [$code, $other['device']->id]))->assertNotFound();
    $this->actingAs($setup['manager'])->post(route('settings.pos.staff-pins.revoke', [$code, $other['cashier']->id]))->assertNotFound();
    $this->actingAs($other['manager'])->post(route('settings.pos.devices.revoke', [$code, $setup['device']->id]))->assertNotFound();
});

it('clears a staff PIN from the settings route', function () {
    $setup = nativePosSetup();

    $this->actingAs($setup['manager'])->post(route('settings.pos.staff-pins.revoke', [$setup['property']->code, $setup['cashier']->id]));

    expect(PosStaffPin::query()->where('user_id', $setup['cashier']->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'pos.pin_revoked')->count())->toBe(1);
});
