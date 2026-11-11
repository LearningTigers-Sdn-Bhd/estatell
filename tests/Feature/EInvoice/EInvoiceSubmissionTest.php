<?php

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Actions\EInvoice\SubmitFolioEInvoice;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\ReopenFolio;
use App\Enums\ArInvoiceType;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceScenario;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\IdentityDocumentType;
use App\Enums\TransactionKind;
use App\Features\EInvoicing;
use App\Jobs\RefreshEInvoiceStatusJob;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\PropertyGuestEmail;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\TransactionCode;
use App\Models\User;
use App\Notifications\EInvoiceForGuest;
use App\Services\EInvoice\MockMyInvoisClient;
use App\Services\EInvoice\MyInvoisApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

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
        'supplier_email' => 'accounts@hotel.my',
        'supplier_city' => 'Kuala Lumpur',
    ]);

    $guest = Guest::factory()->for($property)->create([
        'full_name' => 'Ahmad Razak',
        'tin' => 'IG1234567890',
        'city' => 'Kuala Lumpur',
        'email' => 'ahmad@example.my',
        'phone' => '+60123456789',
        'state_code' => '14',
    ]);

    GuestIdentityDocument::factory()->for($guest)->create(['type' => IdentityDocumentType::MyKad, 'number' => '900101125555']);

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

afterEach(fn () => MockMyInvoisClient::resetOverrides());

/**
 * Submit the setup folio's e-invoice and return the stored submission.
 *
 * @param  array{owner: User, property: Property, reservation: Reservation, folio: Folio}  $setup
 */
function submitSetupFolio(array $setup): EInvoiceSubmission
{
    test()->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'success');

    return EInvoiceSubmission::where('folio_id', $setup['folio']->id)->firstOrFail();
}

it('submits a closed folio and transitions to Valid status via Mock client', function () {
    $setup = submissionSetup();

    $this->actingAs($setup['owner'])
        ->from("/p/{$setup['property']->code}/finance/e-invoices")
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertRedirect("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertInertiaFlash('toast.type', 'success');

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
        ->assertInertiaFlash('toast.type', 'error');

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
        ->assertInertiaFlash('toast.type', 'success');

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
        ->assertInertiaFlash('toast.type', 'error');

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
        ->assertInertiaFlash('toast.type', 'success');

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
        ->assertInertiaFlash('toast.type', 'error');

    $original->refresh();
    expect($original->status)->toBe(EInvoiceStatus::Valid);
});

it('submits a city ledger AR invoice', function () {
    $setup = submissionSetup();

    $company = Company::factory()->for($setup['property'])->create([
        'name' => 'Petronas Dagangan Bhd',
        'tin' => 'C9876543210',
        'brn' => '198201002233',
        'billing_city' => 'Kuala Lumpur',
        'billing_state_code' => '14',
        'billing_country_code' => 'MY',
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
        ->assertInertiaFlash('toast.type', 'success');

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

    // Dashboard index: the page reads the paginator as data, links and meta.
    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/e-invoices/index')
            ->has('submissions.data', 1)
            ->has('submissions.links')
            ->where('submissions.meta.current_page', 1)
            ->where('submissions.meta.total', 1)
            ->etc());

    // Details view
    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertOk();
});

it('sends the issue date and time in UTC', function () {
    // 20:30 UTC is 04:30 the next day in Kuala Lumpur.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 20:30:00', 'UTC'));

    $invoice = submitSetupFolio(submissionSetup())->document_payload['Invoice'][0];

    expect($invoice['IssueDate'][0]['_'])->toBe('2026-10-14')
        ->and($invoice['IssueTime'][0]['_'])->toBe('20:30:00Z');
});

it('sends a three-letter country code for a foreign guest', function () {
    $setup = submissionSetup();
    $setup['folio']->payerGuest->forceFill(['country_code' => 'SG', 'nationality_code' => 'SG', 'tin' => null])->save();
    GuestIdentityDocument::factory()->passport()->for($setup['folio']->payerGuest)->create(['number' => 'E1234567A']);

    $submission = submitSetupFolio($setup);
    $buyer = $submission->document_payload['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0];

    expect($buyer['PostalAddress'][0]['Country'][0]['IdentificationCode'][0]['_'])->toBe('SGP')
        ->and($buyer['PartyIdentification'][0]['ID'][0]['_'])->toBe('EI00000000020')
        ->and($submission->buyer_snapshot['country_code'])->toBe('SGP');
});

it('queues a status refresh while LHDN is still validating', function () {
    Queue::fake();
    MockMyInvoisClient::$documentDetailsOverride = fn (string $uuid): array => ['uuid' => $uuid, 'status' => 'Submitted'];

    $submission = submitSetupFolio(submissionSetup());

    expect($submission->status)->toBe(EInvoiceStatus::Submitted);
    Queue::assertPushed(RefreshEInvoiceStatusJob::class, fn (RefreshEInvoiceStatusJob $job): bool => $job->submissionId === $submission->id);
});

it('does not queue a status refresh once LHDN has validated', function () {
    Queue::fake();

    expect(submitSetupFolio(submissionSetup())->status)->toBe(EInvoiceStatus::Valid);
    Queue::assertNotPushed(RefreshEInvoiceStatusJob::class);
});

it('refuses submission if domestic guest lacks a tax number', function () {
    $setup = submissionSetup();
    $setup['folio']->payerGuest->forceFill(['tin' => null])->save();

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'error');

    expect(EInvoiceSubmission::where('folio_id', $setup['folio']->id)->exists())->toBeFalse();
});

it('refuses submission if buyer city is missing', function () {
    $setup = submissionSetup();
    $setup['folio']->payerGuest->forceFill(['city' => null])->save();

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'error');

    expect(EInvoiceSubmission::where('folio_id', $setup['folio']->id)->exists())->toBeFalse();
});

it('refuses submission if foreign guest lacks a passport number', function () {
    $setup = submissionSetup();
    $setup['folio']->payerGuest->forceFill(['country_code' => 'SG', 'nationality_code' => 'SG', 'tin' => null])->save();

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'error');

    expect(EInvoiceSubmission::where('folio_id', $setup['folio']->id)->exists())->toBeFalse();
});

it('excludes stays over RM 10,000 from monthly consolidation and does not re-consolidate', function () {
    $setup = submissionSetup();
    $property = $setup['property'];

    $res1 = Reservation::factory()->for($property)->create();
    $res2 = Reservation::factory()->for($property)->create();

    // Setup folio with amount over RM 10,000
    $expensiveFolio = Folio::factory()->for($property)->closed()->create([
        'reservation_id' => $res1->id,
        'type' => FolioType::Master,
        'closed_at' => CarbonImmutable::parse('2026-10-10 12:00:00'),
    ]);
    FolioLine::factory()->for($expensiveFolio)->create([
        'transaction_code_id' => $setup['folio']->lines->first()->transaction_code_id,
        'kind' => TransactionKind::Charge,
        'amount' => '12000.00',
        'unit_amount' => '12000.00',
    ]);

    // Setup eligible folio under RM 10,000
    $normalFolio = Folio::factory()->for($property)->closed()->create([
        'reservation_id' => $res2->id,
        'type' => FolioType::Master,
        'closed_at' => CarbonImmutable::parse('2026-10-10 12:00:00'),
    ]);
    FolioLine::factory()->for($normalFolio)->create([
        'transaction_code_id' => $setup['folio']->lines->first()->transaction_code_id,
        'kind' => TransactionKind::Charge,
        'amount' => '450.00',
        'unit_amount' => '450.00',
    ]);

    $consolidateAction = app(ConsolidateMonthlyEInvoices::class);

    // First consolidation run
    $submissions = $consolidateAction($property, CarbonImmutable::parse('2026-10-01'));
    expect($submissions)->toHaveCount(1);
    expect($submissions[0]->folios->pluck('id'))
        ->toContain($normalFolio->id)
        ->toContain($setup['folio']->id)
        ->not->toContain($expensiveFolio->id);

    // Second consolidation run in same month should not find any eligible folios
    $secondRun = $consolidateAction($property, CarbonImmutable::parse('2026-10-01'));
    expect($secondRun)->toBeEmpty();
});

it('submits an individual e-invoice from front desk with custom buyer snapshot', function () {
    Queue::fake([RefreshEInvoiceStatusJob::class]);

    $setup = submissionSetup();
    $owner = $setup['owner'];
    $property = $setup['property'];
    $res = $setup['reservation'];
    $folio = $setup['folio'];

    $response = $this->actingAs($owner)->post(
        route('reservations.folios.e-invoice', [
            'property' => $property->code,
            'reservation' => $res->id,
            'folio' => $folio->id,
        ]),
        [
            'requested_by_guest' => true,
            'buyer_snapshot' => [
                'name' => 'John Doe International',
                'id_type' => 'PASSPORT',
                'id_value' => 'A12345678',
                'tin' => null,
                'email' => 'john.doe@global.com',
                'phone' => '+65 9123 4567',
                'address_line1' => '10 Orchard Road',
                'address_line2' => '',
                'city' => 'Singapore',
                'postal_code' => '238888',
                'state_code' => '17',
                'country_code' => 'SGP',
                'sst_registration' => null,
            ],
        ]
    );

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $submission = EInvoiceSubmission::query()
        ->where('folio_id', $folio->id)
        ->latest('id')
        ->first();

    expect($submission)->not->toBeNull();
    expect($submission->requested_by_guest)->toBeTrue();
    expect($submission->status)->toBe(EInvoiceStatus::Valid);
    expect($submission->buyer_snapshot['name'])->toBe('John Doe International');
    expect($submission->buyer_snapshot['country_code'])->toBe('SGP');

    // Foreign guest with null TIN in snapshot gets LHDN foreign TIN EI00000000020 in the document payload
    $ublCustomer = $submission->document_payload['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0];
    $tinId = collect($ublCustomer['PartyIdentification'])->firstWhere('ID.0.schemeID', 'TIN')['ID'][0]['_'];
    expect($tinId)->toBe('EI00000000020');
});

it('answers 404 to every e-invoice route while the feature is off', function () {
    $setup = submissionSetup();
    $code = $setup['property']->code;
    Feature::for($setup['property'])->deactivate(EInvoicing::class);
    $arInvoice = ArInvoice::factory()->for($setup['property'])->create();

    $this->actingAs($setup['owner']);
    $this->post("/p/{$code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")->assertNotFound();
    $this->post("/p/{$code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice/cancel")->assertNotFound();
    $this->post("/p/{$code}/finance/city-ledger/invoices/{$arInvoice->id}/e-invoice")->assertNotFound();
    $this->post("/p/{$code}/finance/city-ledger/invoices/{$arInvoice->id}/e-invoice/cancel")->assertNotFound();
    $this->get("/p/{$code}/finance/e-invoices")->assertNotFound();
    $this->post("/p/{$code}/finance/e-invoices/consolidate")->assertNotFound();
    $this->get("/p/{$code}/settings/e-invoice")->assertNotFound();

    expect(EInvoiceSubmission::query()->count())->toBe(0);
});

it('does not submit a folio while the feature is off, so checkout auto-submit stays quiet', function () {
    $setup = submissionSetup();
    Feature::for($setup['property'])->deactivate(EInvoicing::class);

    $result = app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);

    expect($result->ok)->toBeFalse()
        ->and(EInvoiceSubmission::query()->count())->toBe(0);
});

it('lets staff resubmit a folio after LHDN rejected its e-invoice', function () {
    $setup = submissionSetup();
    MockMyInvoisClient::$documentDetailsOverride = fn (string $uuid): array => ['uuid' => $uuid, 'status' => 'Invalid'];

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'LHDN rejected e-invoice '.EInvoiceSubmission::query()->sole()->internal_id.'. Nothing was filed.');
    $rejected = EInvoiceSubmission::query()->sole();
    expect($rejected->status)->toBe(EInvoiceStatus::Invalid);

    MockMyInvoisClient::resetOverrides();
    submitSetupFolio($setup);

    expect(EInvoiceSubmission::query()->where('folio_id', $setup['folio']->id)->pluck('status')->all())
        ->toBe([EInvoiceStatus::Invalid, EInvoiceStatus::Valid]);
});

it('refuses an individual e-invoice for a stay already in a consolidated batch', function () {
    $setup = submissionSetup();
    $batch = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'scenario' => EInvoiceScenario::Consolidation,
        'is_consolidated' => true,
        'status' => EInvoiceStatus::Valid,
    ]);
    $batch->folios()->attach($setup['folio']->id);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'error');

    expect(EInvoiceSubmission::query()->where('folio_id', $setup['folio']->id)->exists())->toBeFalse();
});

it('sends a three-letter buyer country on a credit note for a front-desk e-invoice', function () {
    $setup = submissionSetup();

    $this->actingAs($setup['owner'])->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice", [
        'buyer_snapshot' => [
            'name' => 'Ahmad Razak', 'tin' => 'IG1234567890', 'id_type' => 'NRIC', 'id_value' => '900101125555',
            'email' => 'ahmad@example.my', 'phone' => '+60123456789', 'address_line1' => 'Jalan Ampang',
            'city' => 'Kuala Lumpur', 'postal_code' => '50450', 'state_code' => '14', 'country_code' => 'MY',
        ],
    ])->assertInertiaFlash('toast.type', 'success');
    $original = EInvoiceSubmission::query()->where('folio_id', $setup['folio']->id)->firstOrFail();

    $this->post("/p/{$setup['property']->code}/finance/e-invoices/{$original->id}/credit-note", [
        'credit_note_number' => 'CN-2026-002', 'amount' => '10.00', 'reason' => 'Late checkout fee waived',
    ])->assertInertiaFlash('toast.type', 'success');

    $creditNote = EInvoiceSubmission::query()->where('original_submission_id', $original->id)->firstOrFail();
    $country = $creditNote->document_payload['Invoice'][0]['AccountingCustomerParty'][0]['Party'][0]['PostalAddress'][0]['Country'][0]['IdentificationCode'][0]['_'];

    expect($original->buyer_snapshot['country_code'])->toBe('MYS')
        ->and($country)->toBe('MYS');
});

it('shows the LHDN error when a status refresh fails', function () {
    $setup = submissionSetup();
    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Submitted,
        'uuid' => 'MOCK-UUID-1',
    ]);
    MockMyInvoisClient::$documentDetailsOverride = fn (string $uuid): array => throw new MyInvoisApiException('It seems that you are not authorized to get document details', 403);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}/refresh")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', 'It seems that you are not authorized to get document details');

    expect($submission->fresh()->status)->toBe(EInvoiceStatus::Submitted);
});

it('lists the reasons from LHDN validation steps on a rejected e-invoice', function () {
    $submission = EInvoiceSubmission::factory()->make([
        'error_details' => ['validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['status' => 'Valid', 'name' => 'Step04-Code Field Validator'],
            ['status' => 'Invalid', 'name' => 'Step05-Taxpayer Profile Validator', 'error' => [
                'error' => 'Step05-Invalid Taxpayer Profile Validator',
                'innerError' => [['errorCode' => 'ERR206', 'error' => 'NRIC/ARMY mismatch or not exist in HITS']],
            ]],
        ]]],
    ]);

    expect($submission->errorMessages())->toBe(['NRIC/ARMY mismatch or not exist in HITS']);
});

it('shows a hotel manager the e-invoice in plain terms, without the LHDN payload', function () {
    $setup = submissionSetup();
    $submission = submitSetupFolio($setup);

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/e-invoices/show')
            ->where('submission.status.value', 'valid')
            ->where('submission.amounts.total', '350.00')
            ->where('submission.buyer.name', 'Ahmad Razak')
            ->where('submission.reservation.folio_id', $setup['folio']->id)
            ->where('submission.can_credit_note', true)
            ->missing('submission.document_payload')
            ->missing('submission.raw_response')
            ->missing('submission.submission_uid')
            ->etc());
});

it('masks the buyer ID and explains an LHDN rejection with a fix', function () {
    $setup = submissionSetup();
    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Invalid,
        'buyer_snapshot' => ['name' => 'Test Guest', 'tin' => 'IG12345678900', 'id_type' => 'NRIC', 'id_value' => '900101125555', 'city' => 'Kota Kinabalu'],
        'error_details' => ['validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['status' => 'Invalid', 'name' => 'Step05-Taxpayer Profile Validator', 'error' => ['innerError' => [
                ['errorCode' => 'ERR206', 'error' => 'NRIC/ARMY mismatch or not exist in HITS Please refer to https://sdk.myinvois.hasil.gov.my/x'],
            ]]],
            ['status' => 'Invalid', 'name' => 'Step04-Code Field Validator', 'error' => ['innerError' => [
                ['errorCode' => 'XX999', 'error' => 'Something new Please refer to https://sdk.myinvois.hasil.gov.my/y'],
            ]]],
        ]]],
    ]);

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.buyer.id_label', 'IC (MyKad)')
            ->where('submission.buyer.id_number', '•••• 5555')
            ->where('submission.reasons', [
                ['message' => "The guest's IC number does not match their tax number at LHDN.", 'fix' => 'Check the IC and tax number with the guest, then submit again.', 'where' => 'buyer'],
                ['message' => 'Something new', 'fix' => 'Check the buyer details, then submit again.', 'where' => 'buyer'],
            ])
            ->where('submission.can_resubmit', true)
            ->etc());
});

it('sends an error in the hotel details to E-invoice settings and words a company mismatch for a company', function () {
    $setup = submissionSetup();
    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'status' => EInvoiceStatus::Invalid,
        'buyer_snapshot' => ['name' => 'Sabah Energy Sdn Bhd', 'tin' => 'C12345678900', 'id_type' => 'BRN', 'id_value' => '201901012345'],
        'error_details' => ['validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['status' => 'Invalid', 'name' => 'Step03', 'error' => ['innerError' => [
                ['errorCode' => 'CF321', 'error' => 'Invalid phone', 'propertyPath' => 'Invoice.AccountingSupplierParty.Party.Contact.Telephone'],
                ['errorCode' => 'ERR206', 'error' => 'BRN mismatch', 'propertyPath' => 'Invoice.AccountingCustomerParty.Party.PartyIdentification.ID'],
            ]]],
        ]]],
    ]);

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.reasons.0.where', 'hotel')
            ->where('submission.reasons.1.message', "The company's registration number does not match its tax number at LHDN.")
            ->where('submission.reasons.1.where', 'buyer')
            ->etc());
});

it('offers no resubmit once a newer e-invoice replaced the rejected one', function () {
    $setup = submissionSetup();
    $rejected = EInvoiceSubmission::factory()->for($setup['property'])->create(['folio_id' => $setup['folio']->id, 'status' => EInvoiceStatus::Invalid]);
    submitSetupFolio($setup);

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$rejected->id}")
        ->assertInertia(fn (Assert $page) => $page->where('submission.can_resubmit', false)->etc());
});

it('opens the folio e-invoice form with what LHDN rejected and why', function () {
    $setup = submissionSetup();
    EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'status' => EInvoiceStatus::Invalid,
        'buyer_snapshot' => ['name' => 'Ahmad Razak', 'tin' => 'IG99999999990', 'id_type' => 'NRIC', 'id_value' => '900101125555', 'country_code' => 'MYS'],
        'error_details' => ['validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['status' => 'Invalid', 'name' => 'Step05', 'error' => ['innerError' => [['errorCode' => 'ERR206', 'error' => 'NRIC mismatch']]]],
        ]]],
    ]);

    $this->actingAs($setup['owner'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('folio.canSubmitEInvoice', true)
            ->where('folio.eInvoiceBuyer.tin', 'IG99999999990')
            ->where('folio.eInvoiceRejection', [
                ['message' => "The guest's IC number does not match their tax number at LHDN.", 'fix' => 'Check the IC and tax number with the guest, then submit again.'],
            ])
            ->etc());
});

it('finds e-invoices by the guest or company name and filters by type', function () {
    $setup = submissionSetup();
    $ahmad = EInvoiceSubmission::factory()->for($setup['property'])->create(['internal_id' => 'INV-1', 'buyer_snapshot' => ['name' => 'Ahmad Razak']]);
    EInvoiceSubmission::factory()->for($setup['property'])->create(['internal_id' => 'INV-2', 'buyer_snapshot' => ['name' => 'Sabah Energy Sdn Bhd']]);
    $summary = EInvoiceSubmission::factory()->for($setup['property'])->create(['internal_id' => 'CONS-1', 'is_consolidated' => true, 'scenario' => EInvoiceScenario::Consolidation]);

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices?search=ahmad")
        ->assertInertia(fn (Assert $page) => $page
            ->has('submissions.data', 1)
            ->where('submissions.data.0.id', $ahmad->id)
            ->where('submissions.data.0.billed_to', 'Ahmad Razak')
            ->etc());

    $this->get("/p/{$setup['property']->code}/finance/e-invoices?type=summary")
        ->assertInertia(fn (Assert $page) => $page
            ->has('submissions.data', 1)
            ->where('submissions.data.0.id', $summary->id)
            ->where('submissions.data.0.billed_to', 'General public')
            ->etc());
});

it('emails the guest their e-invoice in the property wording, with the MyInvois link', function () {
    Notification::fake();
    $setup = submissionSetup();
    $submission = submitSetupFolio($setup);
    PropertyGuestEmail::factory()->for($setup['property'])->create([
        'subject' => 'Receipt {invoice_number} from {hotel_name}',
        'body' => "Hi {guest_name},\n\nYour e-invoice is {amount}.",
    ]);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}/email", ['to' => 'ahmad@example.my'])
        ->assertInertiaFlash('toast.type', 'success');

    Notification::assertSentOnDemand(EInvoiceForGuest::class, function (EInvoiceForGuest $mail, array $channels, object $notifiable) use ($setup, $submission): bool {
        return $notifiable->routes['mail'] === 'ahmad@example.my'
            && $mail->subject === "Receipt {$submission->internal_id} from {$setup['property']->name}"
            && $mail->paragraphs === ['Hi Ahmad Razak,', 'Your e-invoice is MYR 350.00.']
            && $mail->viewUrl === $submission->fresh()->validationUrl();
    });
    expect(Activity::query()->where('event', 'einvoice.emailed')->count())->toBe(1);
});

it('does not email an e-invoice LHDN has not accepted', function () {
    Notification::fake();
    $setup = submissionSetup();
    $submission = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'scenario' => EInvoiceScenario::Folio,
        'status' => EInvoiceStatus::Invalid,
    ]);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}/email", ['to' => 'ahmad@example.my'])
        ->assertInertiaFlash('toast.type', 'error');

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'einvoice.emailed')->count())->toBe(0);
});

it('keeps a folio closed while LHDN holds its e-invoice, and frees it once rejected or cancelled', function (EInvoiceStatus $status, bool $reopens) {
    $setup = submissionSetup();
    EInvoiceSubmission::factory()->for($setup['property'])->create(['folio_id' => $setup['folio']->id, 'internal_id' => 'INV-7', 'status' => $status]);

    if (! $reopens) {
        $this->actingAs($setup['owner'])
            ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('folio.canReopen', false)
                ->where('folio.closedNotice', 'On e-invoice INV-7 sent to LHDN, so it cannot reopen. Post a late charge on a new folio. To lower the amount, issue a credit note on the e-invoice.')
                ->etc());
    }

    $result = app(ReopenFolio::class)($setup['owner'], $setup['folio']);

    expect($result->refusal)->toBe($reopens ? null : FolioStatusResult::REFUSAL_HAS_E_INVOICE)
        ->and($setup['folio']->fresh()->status)->toBe($reopens ? FolioStatus::Open : FolioStatus::Closed);
})->with([
    'valid' => [EInvoiceStatus::Valid, false],
    'being checked' => [EInvoiceStatus::Submitted, false],
    'rejected' => [EInvoiceStatus::Invalid, true],
    'cancelled' => [EInvoiceStatus::Cancelled, true],
]);

it('keeps a folio in a monthly summary closed', function () {
    $setup = submissionSetup();
    $summary = EInvoiceSubmission::factory()->for($setup['property'])->create(['is_consolidated' => true, 'scenario' => EInvoiceScenario::Consolidation, 'status' => EInvoiceStatus::Valid]);
    $summary->folios()->attach($setup['folio']->id);

    $result = app(ReopenFolio::class)($setup['owner'], $setup['folio']);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_HAS_E_INVOICE);
});

it('checks a tax number with LHDN from the Issue e-invoice form', function (?string $tin, string $result, ?string $answerTin) {
    $setup = submissionSetup();
    MockMyInvoisClient::$taxNumbers = ['NRIC:900101125555' => 'IG12345678900'];

    $this->actingAs($setup['owner'])
        ->postJson("/p/{$setup['property']->code}/finance/e-invoices/tax-number/check", ['tin' => $tin, 'id_type' => 'NRIC', 'id_value' => '900101-12-5555'])
        ->assertOk()
        ->assertJson(['result' => $result, 'tin' => $answerTin]);
})->with([
    'matches' => ['IG12345678900', 'match', 'IG12345678900'],
    'another number' => ['IG99999999990', 'found', 'IG12345678900'],
    'no number given' => [null, 'found', 'IG12345678900'],
]);

it('says when LHDN has no tax number for the ID', function () {
    $setup = submissionSetup();

    $this->actingAs($setup['owner'])
        ->postJson("/p/{$setup['property']->code}/finance/e-invoices/tax-number/check", ['tin' => 'IG99999999990', 'id_type' => 'NRIC', 'id_value' => '900101125555'])
        ->assertOk()
        ->assertJson(['result' => 'not_found', 'message' => 'LHDN has no tax number for this IC. Check the IC number with the buyer.']);
});

it('finds the tax number for a rejected e-invoice and sends it again with it', function () {
    $setup = submissionSetup();
    MockMyInvoisClient::$taxNumbers = ['NRIC:900101125555' => 'IG12345678900'];
    $rejected = EInvoiceSubmission::factory()->for($setup['property'])->create([
        'folio_id' => $setup['folio']->id,
        'status' => EInvoiceStatus::Invalid,
        'buyer_snapshot' => ['name' => 'Ahmad Razak', 'tin' => 'IG99999999990', 'id_type' => 'NRIC', 'id_value' => '900101125555', 'country_code' => 'MYS', 'state_code' => '12', 'city' => 'Kota Kinabalu', 'address_line1' => '1 Jalan Pantai', 'phone' => '+60123456789', 'email' => 'ahmad@example.my'],
        'error_details' => ['validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['status' => 'Invalid', 'name' => 'Step05', 'error' => ['innerError' => [['errorCode' => 'ERR206', 'error' => 'NRIC mismatch']]]],
        ]]],
    ]);
    $base = "/p/{$setup['property']->code}/finance/e-invoices/{$rejected->id}";

    $this->actingAs($setup['owner'])->get($base)->assertInertia(fn (Assert $page) => $page->where('submission.can_find_tax_number', true)->etc());

    $this->postJson("{$base}/tax-number")->assertOk()->assertJson(['result' => 'found', 'tin' => 'IG12345678900']);

    $this->post("{$base}/resubmit", ['tin' => 'IG12345678900'])->assertInertiaFlash('toast.type', 'success');

    $resent = EInvoiceSubmission::query()->where('folio_id', $setup['folio']->id)->whereKeyNot($rejected->id)->sole();
    expect($resent->status)->toBe(EInvoiceStatus::Valid)
        ->and($resent->buyer_snapshot['tin'])->toBe('IG12345678900')
        ->and($resent->buyer_snapshot['id_value'])->toBe('900101125555');
});

it('refuses to resend a City ledger e-invoice with a tax number', function () {
    $setup = submissionSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $invoice = ArInvoice::factory()->for($setup['property'])->create(['company_id' => $company->id]);
    $rejected = EInvoiceSubmission::factory()->for($setup['property'])->create(['ar_invoice_id' => $invoice->id, 'status' => EInvoiceStatus::Invalid]);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$rejected->id}/resubmit", ['tin' => 'C12345678900'])
        ->assertInertiaFlash('toast.message', 'Only a rejected stay e-invoice can be sent again from here.');
});

it('sends a stay billed to a company through its city ledger invoice, not the folio or the monthly summary', function () {
    $setup = submissionSetup();
    $company = Company::factory()->for($setup['property'])->create();
    ArInvoice::factory()->for($setup['property'])->create(['company_id' => $company->id, 'folio_id' => $setup['folio']->id, 'number' => 'AR-0042']);

    $result = app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);
    expect($result->refusal)->toBe('billed_to_city_ledger')
        ->and($result->message)->toBe('This stay was billed to a company on city ledger invoice AR-0042. Send the e-invoice from that invoice instead.');

    $this->actingAs($setup['owner'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page->where('folio.canSubmitEInvoice', false)->etc());

    expect(app(ConsolidateMonthlyEInvoices::class)($setup['property'], CarbonImmutable::now('Asia/Kuala_Lumpur'), $setup['owner']))->toBe([]);
});

it('shows a folio in a monthly summary as such and offers no e-invoice of its own', function () {
    $setup = submissionSetup();
    $summary = EInvoiceSubmission::factory()->for($setup['property'])->create(['is_consolidated' => true, 'scenario' => EInvoiceScenario::Consolidation, 'status' => EInvoiceStatus::Valid]);
    $summary->folios()->attach($setup['folio']->id);

    $this->actingAs($setup['owner'])
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('folio.canSubmitEInvoice', false)
            ->where('folio.eInvoiceSubmissionId', $summary->id)
            ->where('folio.eInvoiceStatusLabel', 'In monthly summary')
            ->etc());
});

it('asks for the guest IC before sending, since LHDN checks it against the tax number', function () {
    $setup = submissionSetup();
    $setup['folio']->payerGuest->documents()->delete();

    $result = app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);

    expect($result->message)->toBe("Enter the guest's IC number. LHDN checks it against the tax number.")
        ->and(EInvoiceSubmission::query()->count())->toBe(0);
});

it('names the missing hotel details and where to add them', function () {
    $setup = submissionSetup();
    $setup['setting']->update(['supplier_city' => null, 'supplier_email' => null]);
    $setup['property']->forceFill(['city' => null, 'contact_email' => null])->save();

    $result = app(SubmitFolioEInvoice::class)($setup['folio']->fresh(), $setup['owner']);

    expect($result->message)->toBe("Your hotel's email and city are missing. Add them in E-invoice settings, then send again.");
});

it('refuses a credit note larger than what is left on the e-invoice', function () {
    $setup = submissionSetup();
    $original = submitSetupFolio($setup);

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/{$original->id}/credit-note", ['credit_note_number' => 'CN-1', 'amount' => '400.00', 'reason' => 'Refund'])
        ->assertInertiaFlash('toast.message', 'A credit note can take off at most 350.00, what is left on this e-invoice.');
});

it('records an LHDN timeout as a send that did not reach LHDN, instead of failing the page', function () {
    $setup = submissionSetup();
    $setup['setting']->update(['api_environment' => EInvoiceEnvironment::Sandbox]);
    Http::fake(fn () => throw new ConnectionException('Operation timed out'));

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.description', 'We could not reach LHDN. Submit again in a few minutes.');

    expect(EInvoiceSubmission::query()->sole()->status)->toBe(EInvoiceStatus::Invalid);
});

it('offers Send e-invoice on a City ledger invoice and says what the company record is missing', function () {
    $setup = submissionSetup();
    $company = Company::factory()->for($setup['property'])->create(['tin' => null]);
    $invoice = ArInvoice::factory()->for($setup['property'])->create(['company_id' => $company->id, 'folio_id' => $setup['folio']->id]);

    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/city-ledger/invoices/{$invoice->id}")
        ->assertInertia(fn (Assert $page) => $page->where('eInvoice.can_send', true)->where('eInvoice.submission', null)->etc());

    $this->post("/p/{$setup['property']->code}/finance/city-ledger/invoices/{$invoice->id}/e-invoice")
        ->assertInertiaFlash('toast.message', "Add the company's tax number (TIN) on the company record, then send again.");
});
