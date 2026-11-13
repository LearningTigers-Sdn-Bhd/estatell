<?php

use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Features\Concierge;
use App\Features\EInvoicing;
use App\Models\EInvoiceSubmission;
use App\Models\FolioLine;
use App\Models\PropertyEInvoiceSetting;
use App\Models\TransactionCode;
use App\Services\EInvoice\MockMyInvoisClient;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeSetup.php';

afterEach(fn () => MockMyInvoisClient::resetOverrides());

/**
 * A guest who has checked out, with the unit folio closed on the frozen day with an invoice number and one charge, and
 * e-invoicing on the Mock environment.
 *
 * @return array<string, mixed>
 */
function conciergeEInvoiceSetup(): array
{
    $setup = conciergeInStaySetup();
    $property = $setup['property'];
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    $setup['guest']->forceFill(['tin' => 'IG1234567890', 'city' => 'Kuala Lumpur', 'state_code' => '14', 'country_code' => 'MY'])->save();
    $setup['folio']->forceFill(['status' => FolioStatus::Closed, 'closed_at' => now(), 'invoice_number' => 'INV-0001', 'payer_guest_id' => $setup['guest']->id])->save();
    FolioLine::factory()->for($setup['folio'])->create([
        'transaction_code_id' => TransactionCode::factory()->create(['property_id' => $property->id, 'category' => 'room'])->id,
        'kind' => TransactionKind::Charge,
        'amount' => '350.00',
        'unit_amount' => '350.00',
        'description' => 'Deluxe King Room',
    ]);
    Feature::for($property)->activate(EInvoicing::class);
    PropertyEInvoiceSetting::factory()->for($property)->create([
        'enabled' => true,
        'api_environment' => EInvoiceEnvironment::Mock,
        'client_id' => 'mock-client-id',
        'client_secret' => 'mock-client-secret',
        'supplier_name' => 'Mock Grand Hotel Sdn Bhd',
        'supplier_tin' => 'C1234567890',
        'supplier_msic_code' => '55101',
        'supplier_business_description' => 'Hotel operations',
        'supplier_email' => 'accounts@hotel.my',
        'supplier_city' => 'Kuala Lumpur',
    ]);

    return $setup;
}

/**
 * @param  array<string, string>  $with
 * @return array<string, string>
 */
function conciergeEInvoiceBody(array $with = []): array
{
    return ['name' => 'Aisha Rahman', 'id_type' => 'NRIC', 'id_value' => '900101-12-5555', 'tin' => 'IG1234567890', 'city' => 'Kuala Lumpur', 'state_code' => '14', 'country_code' => 'MY', ...$with];
}

it('opens the form for a checked-out guest, filled with the payer\'s name and place but never a stored ID, tax number or contact', function () {
    ['token' => $token] = conciergeEInvoiceSetup();

    $this->get("/c/{$token}/e-invoice")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/e-invoice')
        ->where('eInvoice.state', 'open')
        ->where('eInvoice.buyer.name', fn ($name) => filled($name))
        ->where('eInvoice.buyer.tin', '')
        ->where('eInvoice.buyer.email', '')
        ->where('eInvoice.buyer.phone', '')
        ->where('eInvoice.buyer.idValue', '')
        ->where('eInvoice.buyer.countryCode', 'MY')
        ->where('eInvoice.viewUrl', null));
});

it('sends the guest\'s own e-invoice to LHDN with what they typed, marked as their request', function () {
    ['token' => $token, 'folio' => $folio] = conciergeEInvoiceSetup();

    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody(['email' => 'aisha@example.my']))
        ->assertRedirect("/c/{$token}/e-invoice")
        ->assertInertiaFlash('toast.type', 'success');

    $submission = EInvoiceSubmission::query()->where('folio_id', $folio->id)->sole();
    expect($submission->requested_by_guest)->toBeTrue()
        ->and($submission->status)->toBe(EInvoiceStatus::Valid)
        ->and($submission->buyer_snapshot['id_value'])->toBe('900101-12-5555')
        ->and($submission->buyer_snapshot['email'])->toBe('aisha@example.my');

    $this->get("/c/{$token}/e-invoice")->assertInertia(fn (Assert $page) => $page
        ->where('eInvoice.state', 'valid')
        ->where('eInvoice.buyer', null)
        ->where('eInvoice.viewUrl', fn ($url) => filled($url)));
});

it('does not send a second e-invoice for the same stay', function () {
    ['token' => $token, 'folio' => $folio] = conciergeEInvoiceSetup();

    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody());
    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody())->assertInertiaFlash('toast.type', 'error');

    expect(EInvoiceSubmission::query()->where('folio_id', $folio->id)->count())->toBe(1);
});

it('asks again with the earlier details after LHDN rejects the request', function () {
    ['token' => $token, 'folio' => $folio] = conciergeEInvoiceSetup();
    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody());
    EInvoiceSubmission::query()->where('folio_id', $folio->id)->update(['status' => EInvoiceStatus::Invalid]);

    $this->get("/c/{$token}/e-invoice")->assertInertia(fn (Assert $page) => $page
        ->where('eInvoice.state', 'open')
        ->where('eInvoice.rejection', fn ($text) => filled($text))
        ->where('eInvoice.buyer.idValue', '900101-12-5555')
        ->where('eInvoice.buyer.tin', 'IG1234567890'));
});

it('refuses while the property has e-invoicing off, and does not offer the page after check-out', function () {
    ['token' => $token, 'folio' => $folio, 'property' => $property] = conciergeEInvoiceSetup();
    $property->eInvoiceSetting()->update(['enabled' => false]);

    conciergeStep($token, 'checked_out')->assertInertia(fn (Assert $page) => $page->where('stay.eInvoiceUrl', null));
    $this->get("/c/{$token}/e-invoice")->assertInertia(fn (Assert $page) => $page->where('eInvoice.state', 'unavailable')->where('eInvoice.buyer', null));
    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody())->assertInertiaFlash('toast.type', 'error');

    expect(EInvoiceSubmission::query()->where('folio_id', $folio->id)->exists())->toBeFalse();
});

it('offers the page after check-out and refuses after the month the folio was closed', function () {
    ['token' => $token, 'folio' => $folio] = conciergeEInvoiceSetup();

    conciergeStep($token, 'checked_out')->assertInertia(fn (Assert $page) => $page->where('stay.eInvoiceUrl', "/c/{$token}/e-invoice"));

    // A folio closed in an earlier month (the link still lasts 7 days after departure).
    $folio->forceFill(['closed_at' => now()->subMonth()])->save();

    $this->get("/c/{$token}/e-invoice")->assertInertia(fn (Assert $page) => $page->where('eInvoice.state', 'window_closed'));
    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody())->assertInertiaFlash('toast.type', 'error');
    expect(EInvoiceSubmission::query()->where('folio_id', $folio->id)->exists())->toBeFalse();
});

it('has nothing to request before the folio is closed', function () {
    ['token' => $token, 'folio' => $folio] = conciergeEInvoiceSetup();
    $folio->forceFill(['status' => FolioStatus::Open, 'closed_at' => null])->save();

    $this->get("/c/{$token}/e-invoice")->assertInertia(fn (Assert $page) => $page->where('eInvoice.state', 'after_check_out'));
    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody())->assertInertiaFlash('toast.type', 'error');
});

it('validates the buyer details', function (array $with, string $field) {
    ['token' => $token] = conciergeEInvoiceSetup();

    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody($with))->assertSessionHasErrors($field);
    expect(EInvoiceSubmission::query()->exists())->toBeFalse();
})->with([
    'no name' => [['name' => ''], 'name'],
    'a company id type' => [['id_type' => 'BRN'], 'id_type'],
    'no id number' => [['id_value' => ''], 'id_value'],
    'no city' => [['city' => ''], 'city'],
    'a Malaysian address without a state' => [['state_code' => ''], 'state_code'],
    'an unknown country' => [['country_code' => 'ZZ'], 'country_code'],
]);

it('lets a foreign guest request without a Malaysian state or tax number', function () {
    ['token' => $token, 'folio' => $folio] = conciergeEInvoiceSetup();

    $this->post("/c/{$token}/e-invoice", conciergeEInvoiceBody(['id_type' => 'PASSPORT', 'id_value' => 'A1234567', 'tin' => '', 'state_code' => '', 'country_code' => 'SG']))
        ->assertSessionHasNoErrors();

    $submission = EInvoiceSubmission::query()->where('folio_id', $folio->id)->sole();
    expect($submission->buyer_snapshot['state_code'])->toBe('17')
        ->and($submission->buyer_snapshot['tin'])->toBe('EI00000000020');
});

it('answers 404 for an unknown link and for a property with Concierge off', function () {
    ['token' => $token, 'property' => $property] = conciergeEInvoiceSetup();

    $this->get('/c/'.str_repeat('a', 43).'/e-invoice')->assertNotFound();
    $this->post('/c/'.str_repeat('a', 43).'/e-invoice', conciergeEInvoiceBody())->assertNotFound();

    Feature::for($property)->deactivate(Concierge::class);
    $this->get("/c/{$token}/e-invoice")->assertNotFound();
});
