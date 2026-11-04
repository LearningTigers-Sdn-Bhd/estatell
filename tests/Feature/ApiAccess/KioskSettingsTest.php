<?php

use App\Enums\ApiTokenKind;
use App\Models\ApiToken;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{owner: User, property: Property}
 */
function kioskSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();

    return compact('owner', 'property');
}

it('lists kiosks without any secret', function () {
    ['owner' => $owner, 'property' => $property] = kioskSetup();
    ApiToken::factory()->for($property)->create(['name' => 'Lobby']);
    ApiToken::factory()->for($property)->awaitingPairing()->create(['name' => 'Annex']);
    ApiToken::factory()->for($property)->create(['kind' => ApiTokenKind::Integration, 'name' => 'Not a kiosk']);
    ApiToken::factory()->for(Property::factory())->create(['name' => 'Other property']);

    $this->actingAs($owner)->get(route('settings.kiosks.index', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/kiosks/index')
            ->has('kiosks', 2)
            ->where('kiosks.0.name', 'Annex')
            ->where('kiosks.0.status.value', 'awaiting_pairing')
            ->where('kiosks.1.status.value', 'paired')
            ->missing('kiosks.0.tokenHash')
            ->missing('kiosks.0.pairingCodeHash'));
});

it('adds a kiosk and shows the pairing code only in the flash', function () {
    ['owner' => $owner, 'property' => $property] = kioskSetup();

    $response = $this->actingAs($owner)->post(route('settings.kiosks.store', $property), ['name' => 'Lobby kiosk'])
        ->assertRedirect(route('settings.kiosks.index', $property));

    $pairing = session('inertia.flash_data.pairing') ?? $response->getSession()->get('inertia.flash_data.pairing');
    expect($pairing['name'])->toBe('Lobby kiosk')
        ->and($pairing['code'])->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/');

    $kiosk = $property->apiTokens()->sole();
    expect($kiosk->kind)->toBe(ApiTokenKind::Kiosk)
        ->and($kiosk->scopes)->toBe('kiosk:*')
        ->and($kiosk->pairing_expires_at->equalTo(now()->addMinutes(10)))->toBeTrue()
        ->and($kiosk->token_hash)->toBeNull();
    expect(Activity::query()->where('event', 'api-access.kiosk-added')->exists())->toBeTrue();
});

it('needs a name', function () {
    ['owner' => $owner, 'property' => $property] = kioskSetup();

    $this->actingAs($owner)->post(route('settings.kiosks.store', $property), ['name' => ''])->assertSessionHasErrors('name');
    expect($property->apiTokens()->count())->toBe(0);
});

it('revokes a kiosk and its token stops working', function () {
    ['owner' => $owner, 'property' => $property] = kioskSetup();
    $kiosk = ApiToken::factory()->for($property)->withToken('est_live_revokeme')->create();

    $this->actingAs($owner)->post(route('settings.kiosks.revoke', [$property, $kiosk]))->assertRedirect(route('settings.kiosks.index', $property));

    expect($kiosk->refresh()->revoked_at)->not->toBeNull();
    $this->getJson('/api/kiosk/v1/me', ['Authorization' => 'Bearer est_live_revokeme'])->assertUnauthorized();
    expect(Activity::query()->where('event', 'api-access.token-revoked')->count())->toBe(1);

    $this->actingAs($owner)->post(route('settings.kiosks.revoke', [$property, $kiosk]));
    expect(Activity::query()->where('event', 'api-access.token-revoked')->count())->toBe(1);
});

it('revoking an unused pairing code kills the code', function () {
    ['owner' => $owner, 'property' => $property] = kioskSetup();
    $kiosk = ApiToken::factory()->for($property)->awaitingPairing('ABCD2345')->create();

    $this->actingAs($owner)->post(route('settings.kiosks.revoke', [$property, $kiosk]));

    $this->postJson('/api/kiosk/v1/pair', ['pairing_code' => 'ABCD2345'])->assertUnauthorized();
});

it('does not reach another property or a non-kiosk credential', function () {
    ['owner' => $owner, 'property' => $property] = kioskSetup();
    $foreign = ApiToken::factory()->for(Property::factory())->create();
    $integration = ApiToken::factory()->for($property)->create(['kind' => ApiTokenKind::Integration]);

    $this->actingAs($owner)->post(route('settings.kiosks.revoke', [$property, $foreign]))->assertNotFound();
    $this->actingAs($owner)->post(route('settings.kiosks.revoke', [$property, $integration]))->assertNotFound();
    expect($foreign->refresh()->revoked_at)->toBeNull()->and($integration->refresh()->revoked_at)->toBeNull();
});

it('is closed to staff without settings.manage and to other properties', function () {
    ['property' => $property] = kioskSetup();
    $staff = User::factory()->for($property)->withPermissions([])->create();
    $outsider = User::factory()->owner()->create();

    $this->actingAs($staff)->get(route('settings.kiosks.index', $property))->assertForbidden();
    $this->actingAs($staff)->post(route('settings.kiosks.store', $property), ['name' => 'X'])->assertForbidden();
    $this->actingAs($outsider)->get(route('settings.kiosks.index', $property))->assertNotFound();
});
