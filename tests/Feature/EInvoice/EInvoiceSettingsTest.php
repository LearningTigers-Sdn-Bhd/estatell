<?php

use App\Enums\EInvoiceEnvironment;
use App\Features\EInvoicing;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\PropertySetting;
use App\Models\Tax;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * An owner of a live property that has the E-Invoicing feature on.
 *
 * @return array{0: User, 1: Property}
 */
function eInvoiceOwner(bool $feature = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);

    if ($feature) {
        Feature::for($property)->activate(EInvoicing::class);
    }

    return [$owner, $property];
}

it('answers 404 while the EInvoicing feature is off', function () {
    [$owner, $property] = eInvoiceOwner(feature: false);

    $this->actingAs($owner)
        ->get("/p/{$property->code}/settings/e-invoice")
        ->assertNotFound();
});

it('renders each e-invoice settings tab', function (string $path, string $tab) {
    [$owner, $property] = eInvoiceOwner(feature: true);

    $this->actingAs($owner)
        ->get("/p/{$property->code}/settings/e-invoice{$path}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/e-invoice/edit')
            ->where('tab', $tab)
            ->has('problems'));
})->with([
    'general' => ['', 'general'],
    'connection' => ['/connection', 'connection'],
    'business' => ['/business', 'business'],
]);

it('saves the connection and encrypts the client secret', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice/connection")
        ->put("/p/{$property->code}/settings/e-invoice/connection", [
            'api_environment' => EInvoiceEnvironment::Mock->value,
            'client_id' => 'test-client-id-123',
            'client_secret' => 'super-secret-password-xyz',
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice/connection")
        ->assertSessionHasNoErrors();

    $setting = PropertyEInvoiceSetting::where('property_id', $property->id)->firstOrFail();
    expect($setting->api_environment)->toBe(EInvoiceEnvironment::Mock)
        ->and($setting->client_id)->toBe('test-client-id-123')
        ->and($setting->client_secret)->toBe('super-secret-password-xyz')
        ->and($setting->enabled)->toBeFalse()
        ->and($property->eInvoiceSetting()->toBase()->value('client_secret'))->not->toBe('super-secret-password-xyz');
});

it('keeps the saved client ID and secret when the connection is saved again unchanged', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);
    PropertyEInvoiceSetting::factory()->for($property)->create(['client_id' => 'real-client-id', 'client_secret' => 'real-secret']);

    $shownClientId = null;
    $this->actingAs($owner)
        ->get("/p/{$property->code}/settings/e-invoice/connection")
        ->assertInertia(function (Assert $page) use (&$shownClientId) {
            $page->where('setting.client_id', 'real-client-id');
            $shownClientId = $page->toArray()['props']['setting']['client_id'];
        });

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice/connection")
        ->put("/p/{$property->code}/settings/e-invoice/connection", [
            'api_environment' => EInvoiceEnvironment::Sandbox->value,
            'client_id' => $shownClientId,
            'client_secret' => '',
        ])
        ->assertSessionHasNoErrors();

    $setting = $property->eInvoiceSetting()->firstOrFail();
    expect($setting->client_id)->toBe('real-client-id')
        ->and($setting->client_secret)->toBe('real-secret');
});

it('ignores digital signing fields because signing is not LHDN-compliant yet', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice/connection")
        ->put("/p/{$property->code}/settings/e-invoice/connection", [
            'api_environment' => EInvoiceEnvironment::Sandbox->value,
            'signature_enabled' => true,
            'signing_certificate' => '-----BEGIN CERTIFICATE-----abc-----END CERTIFICATE-----',
            'signing_private_key' => '-----BEGIN PRIVATE KEY-----abc-----END PRIVATE KEY-----',
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice/connection")
        ->assertSessionHasNoErrors();

    $setting = PropertyEInvoiceSetting::where('property_id', $property->id)->firstOrFail();
    expect($setting->signature_enabled)->toBeFalse()
        ->and($setting->signing_certificate)->toBeNull()
        ->and($setting->signing_private_key)->toBeNull();
});

it('saves the business details without touching the other tabs', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);
    PropertyEInvoiceSetting::factory()->for($property)->create([
        'api_environment' => EInvoiceEnvironment::Sandbox,
        'client_id' => 'sb-id',
        'auto_submit_on_checkout' => true,
    ]);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice/business")
        ->put("/p/{$property->code}/settings/e-invoice/business", [
            'supplier_name' => 'Seaside Resort Sdn Bhd',
            'supplier_tin' => 'C1234567890',
            'supplier_msic_code' => '55101',
            'supplier_city' => 'Langkawi',
            'supplier_state_code' => '02',
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice/business")
        ->assertSessionHasNoErrors();

    $setting = $property->eInvoiceSetting()->firstOrFail();
    expect($setting->supplier_name)->toBe('Seaside Resort Sdn Bhd')
        ->and($setting->supplier_tin)->toBe('C1234567890')
        ->and($setting->supplier_state_code)->toBe('02')
        ->and($setting->client_id)->toBe('sb-id')
        ->and($setting->api_environment)->toBe(EInvoiceEnvironment::Sandbox)
        ->and($setting->auto_submit_on_checkout)->toBeTrue();
});

it('tests connection successfully with Mock environment', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    PropertyEInvoiceSetting::factory()->for($property)->create([
        'api_environment' => EInvoiceEnvironment::Mock,
        'client_id' => 'mock-id',
        'client_secret' => 'mock-secret',
        'supplier_tin' => 'C1234567890',
    ]);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice")
        ->post("/p/{$property->code}/settings/e-invoice/test")
        ->assertRedirect("/p/{$property->code}/settings/e-invoice")
        ->assertInertiaFlash('toast.type', 'success');
});

it('requires confirmation when switching to production environment', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    PropertyEInvoiceSetting::factory()->for($property)->create([
        'api_environment' => EInvoiceEnvironment::Sandbox,
        'client_id' => 'sb-id',
        'client_secret' => 'sb-secret',
    ]);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice/connection")
        ->put("/p/{$property->code}/settings/e-invoice/connection", [
            'api_environment' => EInvoiceEnvironment::Production->value,
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice/connection")
        ->assertSessionHasErrors(['confirm_production']);
});

it('switches to production environment when confirmation is checked', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    PropertyEInvoiceSetting::factory()->for($property)->create([
        'api_environment' => EInvoiceEnvironment::Sandbox,
        'client_id' => 'sb-id',
        'client_secret' => 'sb-secret',
    ]);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice/connection")
        ->put("/p/{$property->code}/settings/e-invoice/connection", [
            'api_environment' => EInvoiceEnvironment::Production->value,
            'confirm_production' => true,
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice/connection")
        ->assertSessionHasNoErrors();

    expect($property->eInvoiceSetting()->firstOrFail()->api_environment)->toBe(EInvoiceEnvironment::Production);
});

it('will not switch e-invoicing on until the saved business details LHDN needs are complete', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Feature::for($property)->activate(EInvoicing::class);
    $property->forceFill(['state_code' => 'Sabah', 'registration_number' => '201901012345 (123456-A)', 'postal_code' => null])->save();
    Tax::factory()->for($property)->create(['code' => 'SST', 'enabled' => true]);
    PropertyEInvoiceSetting::factory()->for($property)->create([
        'enabled' => false,
        'supplier_name' => 'Seaside Resort Sdn Bhd',
        'supplier_tin' => 'C1234567890',
        'supplier_msic_code' => '55101',
        'supplier_email' => 'finance@seasideresort.com',
        'supplier_phone' => '+60123456789',
        'supplier_address_line1' => '123 Beach Road',
        'supplier_city' => 'Kota Kinabalu',
        'supplier_postal_code' => null,
        'supplier_sst_registration' => null,
    ]);

    $this->actingAs($owner)
        ->put("/p/{$property->code}/settings/e-invoice", ['enabled' => true])
        ->assertSessionHasErrors(['enabled' => 'Complete the business details before switching e-invoicing on.']);

    expect($property->eInvoiceSetting()->firstOrFail()->enabled)->toBeFalse();

    $this->actingAs($owner)->get("/p/{$property->code}/settings/e-invoice")
        ->assertInertia(fn (Assert $page) => $page
            ->where('problems.supplier_postal_code', 'Enter the 5-digit postcode.')
            ->where('problems.supplier_sst_registration', 'The hotel charges SST, so enter its SST registration number.')
            ->missing('problems.supplier_brn')
            ->missing('problems.supplier_state_code'));

    $this->actingAs($owner)
        ->put("/p/{$property->code}/settings/e-invoice/business", [
            'supplier_postal_code' => '88000',
            'supplier_sst_registration' => 'W10-1808-12345678',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->put("/p/{$property->code}/settings/e-invoice", ['enabled' => true, 'auto_submit_on_checkout' => true])
        ->assertSessionHasNoErrors();

    $setting = $property->eInvoiceSetting()->firstOrFail();
    expect($setting->enabled)->toBeTrue()
        ->and($setting->auto_submit_on_checkout)->toBeTrue()
        ->and($setting->consolidation_enabled)->toBeFalse()
        ->and($setting->effective_from)->not->toBeNull();
});

it('will not clear a business detail LHDN needs while e-invoicing is on', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);
    PropertyEInvoiceSetting::factory()->for($property)->create([
        'enabled' => true,
        'supplier_tin' => 'C1234567890',
    ]);
    $property->forceFill(['tin' => null])->save();

    $this->actingAs($owner)
        ->put("/p/{$property->code}/settings/e-invoice/business", ['supplier_tin' => null])
        ->assertSessionHasErrors(['supplier_tin']);

    expect($property->eInvoiceSetting()->firstOrFail()->supplier_tin)->toBe('C1234567890');
});

it('warns when e-invoicing is already on with hotel details missing', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Feature::for($property)->activate(EInvoicing::class);
    PropertyEInvoiceSetting::factory()->for($property)->create(['supplier_postal_code' => null, 'supplier_brn' => null]);
    $property->forceFill(['postal_code' => null, 'registration_number' => null])->save();

    $this->actingAs($owner)->get("/p/{$property->code}/settings/e-invoice")
        ->assertInertia(fn (Assert $page) => $page
            ->where('problems.supplier_postal_code', 'Enter the 5-digit postcode.')
            ->where('problems.supplier_brn', 'Enter the company registration number (SSM).')
            ->etc());
});
