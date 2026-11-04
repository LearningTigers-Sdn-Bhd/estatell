<?php

use App\Enums\KioskKeyMode;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function kioskSettingsOwner(): User
{
    $owner = User::factory()->owner()->create();
    $owner->property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($owner->property)->create();

    return $owner;
}

it('shows the kiosk settings on the Kiosks page', function () {
    $owner = kioskSettingsOwner();

    $this->actingAs($owner)->get(route('settings.kiosks.index', $owner->property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.keyMode', 'pin')
            ->where('settings.walkInEnabled', false)
            ->where('settings.idleTimeoutSeconds', 60)
            ->has('keyModes', 3));
});

it('saves the kiosk settings and logs the changed fields', function () {
    $owner = kioskSettingsOwner();

    $this->actingAs($owner)->put(route('settings.kiosks.update', $owner->property), ['key_mode' => 'both', 'walk_in_enabled' => true, 'idle_timeout_seconds' => 120])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.kiosks.index', $owner->property));

    $settings = $owner->property->settings()->first();
    expect($settings->kiosk_key_mode)->toBe(KioskKeyMode::Both)
        ->and($settings->kiosk_walk_in_enabled)->toBeTrue()
        ->and($settings->kiosk_idle_timeout_seconds)->toBe(120)
        ->and(Activity::query()->where('event', 'api-access.kiosk-settings-updated')->sole()->properties['changed'])->toContain('kiosk_key_mode');
});

it('rejects a bad key mode or idle time', function (array $body) {
    $owner = kioskSettingsOwner();

    $this->actingAs($owner)->put(route('settings.kiosks.update', $owner->property), $body)->assertSessionHasErrors();
    expect($owner->property->settings()->first()->kiosk_key_mode)->toBe(KioskKeyMode::Pin);
})->with([
    'key mode' => [['key_mode' => 'magic', 'walk_in_enabled' => false, 'idle_timeout_seconds' => 60]],
    'too short' => [['key_mode' => 'pin', 'walk_in_enabled' => false, 'idle_timeout_seconds' => 5]],
    'too long' => [['key_mode' => 'pin', 'walk_in_enabled' => false, 'idle_timeout_seconds' => 9000]],
]);

it('needs the settings permission and stays in the own property', function () {
    $owner = kioskSettingsOwner();
    $other = Property::factory()->create();
    $frontDesk = User::factory()->for($owner->property)->withPermissions([])->create();

    $this->actingAs($frontDesk)->put(route('settings.kiosks.update', $owner->property), ['key_mode' => 'card', 'walk_in_enabled' => false, 'idle_timeout_seconds' => 60])->assertForbidden();
    $this->actingAs($owner)->put(route('settings.kiosks.update', $other), ['key_mode' => 'card', 'walk_in_enabled' => false, 'idle_timeout_seconds' => 60])->assertNotFound();
});
