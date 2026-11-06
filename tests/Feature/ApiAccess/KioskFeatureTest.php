<?php

use App\Features\Kiosks;
use App\Models\Kiosk;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Laravel\Pennant\Feature;

function kioskFeatureOff(): array
{
    $owner = User::factory()->owner()->create();
    $owner->property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($owner->property)->create();

    return ['owner' => $owner, 'property' => $owner->property];
}

it('hides the Kiosks settings while the feature is off', function () {
    ['owner' => $owner, 'property' => $property] = kioskFeatureOff();

    $this->actingAs($owner)->get(route('settings.kiosks.index', $property))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.kiosks.store', $property), ['name' => 'Lobby'])->assertNotFound();

    Feature::for($property)->activate(Kiosks::class);

    $this->actingAs($owner)->get(route('settings.kiosks.index', $property))->assertOk();
});

it('refuses a kiosk token and a pairing code while the feature is off', function () {
    $property = Property::factory()->create();
    Kiosk::factory()->for($property)->withToken('est_live_featureoffaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')->create();
    Kiosk::factory()->for($property)->awaitingPairing('ABCD2345')->create();

    $this->getJson('/api/kiosk/v1/me', ['Authorization' => 'Bearer est_live_featureoffaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])->assertOk();

    Feature::for($property)->deactivate(Kiosks::class);

    $this->getJson('/api/kiosk/v1/me', ['Authorization' => 'Bearer est_live_featureoffaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'kiosk_unauthorized');
    $this->postJson('/api/kiosk/v1/pair', ['code' => 'ABCD2345'])->assertUnauthorized();
});
