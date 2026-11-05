<?php

use App\Enums\EInvoiceEnvironment;
use App\Features\EInvoicing;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\PropertySetting;
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

it('renders the e-invoice settings form when feature is active', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    $this->actingAs($owner)
        ->get("/p/{$property->code}/settings/e-invoice")
        ->assertOk();
});

it('saves e-invoice settings and encrypts sensitive credentials', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    $payload = [
        'enabled' => true,
        'api_environment' => EInvoiceEnvironment::Mock->value,
        'client_id' => 'test-client-id-123',
        'client_secret' => 'super-secret-password-xyz',
        'signature_enabled' => false,
        'supplier_name' => 'Seaside Resort Sdn Bhd',
        'supplier_tin' => 'C1234567890',
        'supplier_sst_registration' => 'W10-1808-12345678',
        'supplier_msic_code' => '55101',
        'supplier_business_description' => 'Hotels and resort hotels',
        'supplier_email' => 'finance@seasideresort.com',
        'supplier_phone' => '+60123456789',
        'supplier_address_line1' => '123 Beach Road',
        'supplier_address_line2' => 'Pantai Cenang',
        'supplier_city' => 'Langkawi',
        'supplier_postal_code' => '07000',
        'supplier_state_code' => '02',
        'auto_submit_on_checkout' => true,
        'consolidation_enabled' => true,
    ];

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice")
        ->put("/p/{$property->code}/settings/e-invoice", $payload)
        ->assertRedirect("/p/{$property->code}/settings/e-invoice");

    $setting = PropertyEInvoiceSetting::where('property_id', $property->id)->first();
    expect($setting)->not->toBeNull();
    expect($setting->api_environment)->toBe(EInvoiceEnvironment::Mock);
    expect($setting->supplier_tin)->toBe('C1234567890');
    expect($setting->client_id)->toBe('test-client-id-123');
    // Secret must be decrypted transparently through cast
    expect($setting->client_secret)->toBe('super-secret-password-xyz');
    expect($setting->supplier_msic_code)->toBe('55101');
    expect($setting->auto_submit_on_checkout)->toBeTrue();
    expect($setting->consolidation_enabled)->toBeTrue();
});

it('keeps the saved client ID when the form is saved again unchanged', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);
    PropertyEInvoiceSetting::factory()->for($property)->create(['client_id' => 'real-client-id']);

    $shownClientId = null;
    $this->actingAs($owner)
        ->get("/p/{$property->code}/settings/e-invoice")
        ->assertInertia(function (Assert $page) use (&$shownClientId) {
            $page->where('setting.client_id', 'real-client-id');
            $shownClientId = $page->toArray()['props']['setting']['client_id'];
        });

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice")
        ->put("/p/{$property->code}/settings/e-invoice", [
            'enabled' => true,
            'api_environment' => EInvoiceEnvironment::Sandbox->value,
            'client_id' => $shownClientId,
            'client_secret' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($property->eInvoiceSetting()->firstOrFail()->client_id)->toBe('real-client-id');
});

it('ignores digital signing fields because signing is not LHDN-compliant yet', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice")
        ->put("/p/{$property->code}/settings/e-invoice", [
            'enabled' => true,
            'api_environment' => EInvoiceEnvironment::Sandbox->value,
            'signature_enabled' => true,
            'signing_certificate' => '-----BEGIN CERTIFICATE-----abc-----END CERTIFICATE-----',
            'signing_private_key' => '-----BEGIN PRIVATE KEY-----abc-----END PRIVATE KEY-----',
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice")
        ->assertSessionHasNoErrors();

    $setting = PropertyEInvoiceSetting::where('property_id', $property->id)->firstOrFail();
    expect($setting->signature_enabled)->toBeFalse()
        ->and($setting->signing_certificate)->toBeNull()
        ->and($setting->signing_private_key)->toBeNull();
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
        ->assertSessionHas('success');
});

it('requires confirmation when switching to production environment', function () {
    [$owner, $property] = eInvoiceOwner(feature: true);

    PropertyEInvoiceSetting::factory()->for($property)->create([
        'api_environment' => EInvoiceEnvironment::Sandbox,
        'client_id' => 'sb-id',
        'client_secret' => 'sb-secret',
    ]);

    $this->actingAs($owner)
        ->from("/p/{$property->code}/settings/e-invoice")
        ->put("/p/{$property->code}/settings/e-invoice", [
            'enabled' => true,
            'api_environment' => EInvoiceEnvironment::Production->value,
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice")
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
        ->from("/p/{$property->code}/settings/e-invoice")
        ->put("/p/{$property->code}/settings/e-invoice", [
            'enabled' => true,
            'api_environment' => EInvoiceEnvironment::Production->value,
            'confirm_production' => true,
        ])
        ->assertRedirect("/p/{$property->code}/settings/e-invoice")
        ->assertSessionHasNoErrors();

    expect($property->eInvoiceSetting()->firstOrFail()->api_environment)->toBe(EInvoiceEnvironment::Production);
});
