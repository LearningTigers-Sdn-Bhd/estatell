<?php

use App\Enums\ArInvoiceType;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\TransactionKind;
use App\Features\EInvoicing;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * Setup a property with live status, open business date, and active mock E-Invoicing.
 *
 * @return array{
 *     owner: User,
 *     property: Property,
 *     setting: PropertyEInvoiceSetting,
 *     reservation: Reservation,
 *     folio: Folio
 * }
 */
function submissionSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    BusinessDate::factory()->create(['property_id' => $property->id]);

    Feature::for($property)->activate(EInvoicing::class);

    $setting = PropertyEInvoiceSetting::factory()->for($property)->create([
        'enabled' => true,
        'api_environment' => EInvoiceEnvironment::Mock,
        'client_id' => 'mock-client-id',
        'client_secret' => 'mock-client-secret',
        'supplier_name' => 'Mock Grand Hotel Sdn Bhd',
        'supplier_tin' => 'C1234567890',
        'supplier_sst_registration' => 'W10-1808-12345678',
        'supplier_msic_code' => '55101',
        'supplier_business_description' => 'Hotel operations',
    ]);

    $guest = Guest::factory()->for($property)->create([
        'full_name' => 'Ahmad Razak',
        'email' => 'ahmad@example.my',
        'phone' => '+60123456789',
        'state_code' => '14',
    ]);

    $reservation = Reservation::factory()->for($property)->create([
        'booker_guest_id' => $guest->id,
    ]);

    $folio = Folio::factory()->for($property)->closed()->create([
        'reservation_id' => $reservation->id,
        'payer_guest_id' => $guest->id,
        'type' => FolioType::Master,
    ]);

    $chargeCode = TransactionCode::factory()->create([
        'property_id' => $property->id,
        'category' => 'room',
    ]);

    FolioLine::factory()->for($folio)->create([
        'transaction_code_id' => $chargeCode->id,
        'kind' => TransactionKind::Charge,
        'amount' => '350.00',
        'unit_amount' => '350.00',
        'description' => 'Deluxe King Room',
    ]);

    return [
        'owner' => $owner,
        'property' => $property,
        'setting' => $setting,
        'reservation' => $reservation,
        'folio' => $folio,
    ];
}

it('submits a closed folio and transitions to Valid status via Mock client', function () {
    $setup = submissionSetup();

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertSessionHas('success');

    $submission = EInvoiceSubmission::where('folio_id', $setup['folio']->id)->first();
    expect($submission)->not->toBeNull();
    expect($submission->document_type)->toBe(EInvoiceDocumentType::Invoice);
    expect($submission->scenario)->toBe(EInvoiceScenario::Folio);
    expect($submission->status)->toBe(EInvoiceStatus::Valid);
    expect($submission->uuid)->not->toBeNull();
    expect($submission->submission_uid)->not->toBeNull();
    expect($submission->long_id)->not->toBeNull();
    expect($submission->validated_at)->not->toBeNull();
});

it('refuses to submit an open folio', function () {
    $setup = submissionSetup();
    $setup['folio']->forceFill(['status' => FolioStatus::Open, 'closed_at' => null, 'closed_by' => null])->save();

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertSessionHas('error');

    expect(EInvoiceSubmission::where('folio_id', $setup['folio']->id)->count())->toBe(0);
});

it('cancels a valid submission within the 72-hour window', function () {
    $setup = submissionSetup();

    // Create a valid submission
    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'document_type' => EInvoiceDocumentType::Invoice,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Valid,
        'validated_at' => CarbonImmutable::now()->subHours(10),
    ]);

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}/cancel", [
            'reason' => 'Wrong buyer identification provided',
        ])
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertSessionHas('success');

    $submission->refresh();
    expect($submission->status)->toBe(EInvoiceStatus::Cancelled);
    expect($submission->cancelled_at)->not->toBeNull();
    expect($submission->cancel_reason)->toBe('Wrong buyer identification provided');
});

it('refuses cancellation after the 72-hour window has expired', function () {
    $setup = submissionSetup();

    // Validated 80 hours ago (> 72h limit)
    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'document_type' => EInvoiceDocumentType::Invoice,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Valid,
        'validated_at' => CarbonImmutable::now()->subHours(80),
    ]);

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}/cancel", [
            'reason' => 'Late cancellation attempt',
        ])
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertSessionHas('error');

    $submission->refresh();
    expect($submission->status)->toBe(EInvoiceStatus::Valid);
    expect($submission->cancelled_at)->toBeNull();
});

it('issues a credit note referencing the original valid e-invoice and blocks original cancellation', function () {
    $setup = submissionSetup();

    $original = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'document_type' => EInvoiceDocumentType::Invoice,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Valid,
        'validated_at' => CarbonImmutable::now()->subHours(24),
    ]);

    // Issue credit note
    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices/{$original->id}")
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$original->id}/credit-note", [
            'credit_note_number' => 'CN-2026-001',
            'amount' => '100.00',
            'reason' => 'Guest room discount after checkout',
        ])
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices/{$original->id}")
        ->assertSessionHas('success');

    $creditNote = EInvoiceSubmission::where('original_submission_id', $original->id)->first();
    expect($creditNote)->not->toBeNull();
    expect($creditNote->document_type)->toBe(EInvoiceDocumentType::CreditNote);
    expect($creditNote->status)->toBe(EInvoiceStatus::Valid);
    expect($creditNote->uuid)->not->toBeNull();

    // Now cancelling the original invoice must be refused because a credit note references it
    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$original->id}/cancel", [
            'reason' => 'Attempting to cancel parent of credit note',
        ])
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertSessionHas('error');

    $original->refresh();
    expect($original->status)->toBe(EInvoiceStatus::Valid);
});

it('submits a city ledger AR invoice', function () {
    $setup = submissionSetup();

    $company = Company::factory()->for($setup['property'])->create([
        'name' => 'Petronas Dagangan Bhd',
        'tin' => 'C9876543210',
        'brn' => '198201002233',
    ]);

    $arInvoice = ArInvoice::factory()->for($setup['property'])->create([
        'company_id' => $company->id,
        'number' => 'INV-2026-001',
        'type' => ArInvoiceType::Invoice,
        'amount' => '1250.00',
    ]);

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/city-ledger/invoices")
        ->post("/p/{$setup['property']->code}/finance/city-ledger/invoices/{$arInvoice->id}/e-invoice")
        ->assertRedirect("/p/{$setup['property']->code}/finance/city-ledger/invoices")
        ->assertSessionHas('success');

    $submission = EInvoiceSubmission::where('ar_invoice_id', $arInvoice->id)->first();
    expect($submission)->not->toBeNull();
    expect($submission->document_type)->toBe(EInvoiceDocumentType::Invoice);
    expect($submission->scenario)->toBe(EInvoiceScenario::ArInvoice);
    expect($submission->status)->toBe(EInvoiceStatus::Valid);
    expect($submission->company_id)->toBe($company->id);
});

it('renders the finance e-invoices dashboard and detail views', function () {
    $setup = submissionSetup();

    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'document_type' => EInvoiceDocumentType::Invoice,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Valid,
    ]);

    // Dashboard index
    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertOk();

    // Details view
    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertOk();
});
