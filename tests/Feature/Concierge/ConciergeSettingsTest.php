<?php

use App\Features\Concierge;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{owner: User, property: Property}
 */
function conciergeSettingsSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    Feature::for($property)->activate(Concierge::class);

    return compact('owner', 'property');
}

it('shows the lobby address and a QR code made from it', function () {
    ['owner' => $owner, 'property' => $property] = conciergeSettingsSetup();

    $this->actingAs($owner)->get(route('settings.concierge.index', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/concierge/index')
            ->where('lobbyUrl', route('concierge.lobby', $property))
            ->where('lobbyQr', fn ($qr) => str_starts_with($qr, 'data:image/svg+xml;base64,')
                && str_contains(base64_decode(substr($qr, strlen('data:image/svg+xml;base64,'))), '<svg')));
});

it('saves the walk-in switch and logs only what changed', function () {
    ['owner' => $owner, 'property' => $property] = conciergeSettingsSetup();

    // Back to the page it came from, so the open tab stays in the URL.
    $this->actingAs($owner)->from(route('settings.concierge.index', [$property, 'tab' => 'lobby']))->put(route('settings.concierge.update', $property), ['walk_in_enabled' => true, 'refund_requests_enabled' => false, 'house_rules' => null])
        ->assertRedirect(route('settings.concierge.index', [$property, 'tab' => 'lobby']));
    $this->actingAs($owner)->put(route('settings.concierge.update', $property), ['walk_in_enabled' => true, 'refund_requests_enabled' => false, 'house_rules' => null]);

    expect($property->refresh()->settings->concierge_walk_in_enabled)->toBeTrue()
        ->and(Activity::query()->where('event', 'concierge.settings-updated')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'concierge.settings-updated')->sole()->properties['changed'])->toContain('concierge_walk_in_enabled');
    $this->actingAs($owner)->get(route('settings.concierge.index', $property))->assertInertia(fn (Assert $page) => $page->where('walkInEnabled', true));
});

it('does not let staff without the settings permission change the switch', function () {
    ['property' => $property] = conciergeSettingsSetup();
    $staff = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($staff)->put(route('settings.concierge.update', $property), ['walk_in_enabled' => true, 'refund_requests_enabled' => false, 'house_rules' => null])->assertForbidden();

    expect((bool) $property->refresh()->settings?->concierge_walk_in_enabled)->toBeFalse();
});

it('answers 404 while the feature is off', function () {
    ['owner' => $owner, 'property' => $property] = conciergeSettingsSetup();
    Feature::for($property)->deactivate(Concierge::class);

    $this->actingAs($owner)->get(route('settings.concierge.index', $property))->assertNotFound();
});

it('needs the settings permission', function () {
    ['property' => $property] = conciergeSettingsSetup();
    $staff = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($staff)->get(route('settings.concierge.index', $property))->assertForbidden();
});

it('does not open the page of another property', function () {
    ['owner' => $owner] = conciergeSettingsSetup();
    $other = Property::factory()->create(['status' => 'live']);
    Feature::for($other)->activate(Concierge::class);

    $this->actingAs($owner)->get(route('settings.concierge.index', $other))->assertNotFound();
});
