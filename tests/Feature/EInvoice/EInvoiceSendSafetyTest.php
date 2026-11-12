<?php

require_once __DIR__.'/../Support/EInvoiceSetup.php';

use App\Actions\EInvoice\CancelEInvoice;
use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Actions\EInvoice\IssueEInvoiceCreditNote;
use App\Actions\EInvoice\RefreshEInvoiceStatus;
use App\Actions\EInvoice\SubmitFolioEInvoice;
use App\Actions\FrontDesk\CheckOutUnit;
use App\Enums\EInvoiceDocumentType;
use App\Enums\EInvoiceEnvironment;
use App\Enums\EInvoiceStatus;
use App\Enums\FolioStatus;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Features\EInvoicing;
use App\Jobs\MonthlyConsolidationJob;
use App\Jobs\SubmitFolioEInvoiceJob;
use App\Models\Company;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\EInvoice\BuyerResolver;
use App\Services\EInvoice\MockMyInvoisClient;
use App\Services\EInvoice\SummaryMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

afterEach(fn () => MockMyInvoisClient::resetOverrides());

/**
 * The setup property with a checked-in unit departing today, whose open Unit folio holds a 350.00 charge paid in full
 * by the setup guest, so check-out closes it.
 *
 * @return array{owner: User, property: Property, setting: PropertyEInvoiceSetting, unit: ReservationUnit, folio: Folio, guest: Guest}
 */
function sendSafetyCheckOutSetup(bool $sendAtCheckOut = true): array
{
    $setup = submissionSetup();
    $setup['setting']->update(['auto_submit_on_checkout' => $sendAtCheckOut]);
    $guest = $setup['folio']->payerGuest;

    $unit = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create([
        'status' => UnitStatus::CheckedIn,
        'checked_in_at' => now()->subDays(2),
        'arrival_date' => today()->subDays(2)->toDateString(),
        'departure_date' => today()->toDateString(),
    ]);
    $folio = Folio::factory()->forUnit($unit)->create([
        'property_id' => $setup['property']->id,
        'reservation_id' => $setup['reservation']->id,
        'payer_guest_id' => $guest->id,
    ]);
    $code = $setup['folio']->lines()->first()->transaction_code_id;
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $code, 'kind' => TransactionKind::Charge, 'amount' => '350.00', 'unit_amount' => '350.00', 'posting_date' => today()->toDateString()]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => TransactionKind::Payment, 'amount' => '-350.00', 'unit_amount' => '-350.00', 'posting_date' => today()->toDateString()]);

    return [...$setup, 'unit' => $unit, 'folio' => $folio, 'guest' => $guest];
}

/**
 * Fake LHDN's test site: the login works; the submit answers as `$submit` says; the search lists what `$found`
 * returns when it is called (a list, or a closure for a list that changes during the test).
 *
 * @param  list<array<string, mixed>>|Closure(): list<array<string, mixed>>  $found
 */
function fakeLhdn(PropertyEInvoiceSetting $setting, Closure $submit, array|Closure $found = [], string $detailsStatus = 'Valid'): void
{
    $list = fn (): array => $found instanceof Closure ? $found() : $found;

    $setting->update(['api_environment' => EInvoiceEnvironment::Sandbox]);

    Http::fake([
        '*/connect/token' => Http::response(['access_token' => 'token']),
        '*/api/v1.0/documentsubmissions' => $submit,
        '*/api/v1.0/documents/search*' => fn () => Http::response(['result' => $list(), 'metadata' => ['totalPages' => 1, 'totalCount' => count($list())]]),
        '*/api/v1.0/documents/*/details' => Http::response(['uuid' => 'LHDN-UUID-1', 'status' => $detailsStatus, 'longId' => 'LONG-1', 'dateTimeValidated' => now()->toIso8601String()]),
    ]);
}

// Send at check-out

it('sends the e-invoice by itself when a guest with their own tax number checks out', function () {
    $setup = sendSafetyCheckOutSetup();

    $result = app(CheckOutUnit::class)($setup['owner'], $setup['unit']);

    $submission = EInvoiceSubmission::query()->where('folio_id', $setup['folio']->id)->sole();
    expect($result->isRefused())->toBeFalse()
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Closed)
        ->and($submission->status)->toBe(EInvoiceStatus::Valid);
});

it('leaves the stay for the monthly summary when the guest has no tax number of their own, or Send at check-out is off', function (?string $tin, bool $sendAtCheckOut) {
    Queue::fake();
    $setup = sendSafetyCheckOutSetup($sendAtCheckOut);
    $setup['guest']->update(['tin' => $tin]);

    app(CheckOutUnit::class)($setup['owner'], $setup['unit']);

    expect($setup['folio']->refresh()->status)->toBe(FolioStatus::Closed);
    Queue::assertNotPushed(SubmitFolioEInvoiceJob::class);
})->with([
    'no tax number' => [null, true],
    "LHDN's foreign placeholder" => [BuyerResolver::FOREIGN_BUYER_TIN, true],
    'Send at check-out off' => ['IG1234567890', false],
]);

it('does not send a company-paid folio by itself', function () {
    Queue::fake();
    $setup = sendSafetyCheckOutSetup();
    $setup['folio']->update(['payer_guest_id' => null, 'payer_company_id' => Company::factory()->for($setup['property'])->create()->id]);

    app(CheckOutUnit::class)($setup['owner'], $setup['unit']);

    Queue::assertNotPushed(SubmitFolioEInvoiceJob::class);
});

it('does not send by itself while the e-invoicing feature is off', function () {
    Queue::fake();
    $setup = sendSafetyCheckOutSetup();
    Feature::for($setup['property'])->deactivate(EInvoicing::class);

    app(CheckOutUnit::class)($setup['owner'], $setup['unit']);

    Queue::assertNotPushed(SubmitFolioEInvoiceJob::class);
});

// A send whose answer was lost

it('keeps a send whose answer was lost waiting for LHDN, and refuses to send it again', function () {
    $setup = submissionSetup();
    fakeLhdn($setup['setting'], fn () => throw new ConnectionException('Operation timed out'));

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/folios/{$setup['folio']->id}/e-invoice")
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.description', 'LHDN may have it. Do not send it again: we are checking with LHDN, and the status updates by itself within 15 minutes.');

    $submission = EInvoiceSubmission::query()->sole();
    expect($submission->status)->toBe(EInvoiceStatus::Pending)
        ->and($submission->submitted_at)->not->toBeNull()
        ->and($submission->uuid)->toBeNull();

    $again = app(SubmitFolioEInvoice::class)($setup['folio']->fresh(), $setup['owner']);
    expect($again->refusal)->toBe('submission_already_exists')
        ->and(EInvoiceSubmission::query()->count())->toBe(1);

    $this->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertInertia(fn (Assert $page) => $page->where('submission.status.value', 'unconfirmed')->where('submission.status.label', 'Waiting for LHDN')->etc());
});

it('finds a document whose answer was lost by its number and reads its status', function () {
    $setup = submissionSetup();
    $found = [];
    $sends = 0;
    fakeLhdn($setup['setting'], function () use (&$sends) {
        $sends++;

        throw new ConnectionException('Operation timed out');
    }, function () use (&$found): array {
        return $found;
    });
    app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);
    $submission = EInvoiceSubmission::query()->sole();
    expect($submission->status)->toBe(EInvoiceStatus::Pending);

    $found = [
        ['uuid' => 'OTHER', 'internalId' => 'SOMETHING-ELSE', 'submissionUID' => 'S0', 'dateTimeReceived' => now()->toIso8601String()],
        ['uuid' => 'LHDN-UUID-1', 'internalId' => $submission->internal_id, 'submissionUID' => 'SUB-1', 'longId' => 'LONG-1', 'status' => 'Valid', 'dateTimeReceived' => now()->toIso8601String()],
    ];
    $refreshed = app(RefreshEInvoiceStatus::class)($submission->fresh());

    expect($refreshed->status)->toBe(EInvoiceStatus::Valid)
        ->and($refreshed->uuid)->toBe('LHDN-UUID-1')
        ->and($refreshed->submission_uid)->toBe('SUB-1');
    // Sent once; found by the look-up, never sent again.
    expect($sends)->toBe(1);
});

it('waits 10 minutes before saying LHDN has no record, then lets staff send again', function () {
    $setup = submissionSetup();
    fakeLhdn($setup['setting'], fn () => throw new ConnectionException('Operation timed out'));
    app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);
    $submission = EInvoiceSubmission::query()->sole();

    $this->travel(9)->minutes();
    expect(app(RefreshEInvoiceStatus::class)($submission->fresh())->status)->toBe(EInvoiceStatus::Pending);

    $this->travel(2)->minutes();
    $refreshed = app(RefreshEInvoiceStatus::class)($submission->fresh());

    expect($refreshed->status)->toBe(EInvoiceStatus::Invalid);
    $this->actingAs($setup['owner'])
        ->get("/p/{$setup['property']->code}/finance/e-invoices/{$submission->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.reasons.0.message', 'We lost the connection while sending it, and 10 minutes later LHDN still has no record of it.')
            ->where('submission.reasons.0.fix', "Look for {$submission->internal_id} in the MyInvois portal. If it is not there, send it again. If it is there, contact support before sending anything.")
            ->where('submission.can_resubmit', true)
            ->etc());
});

it('treats an LHDN answer as lost only when LHDN may have the document', function (int $status, array $body, EInvoiceStatus $expected) {
    $setup = submissionSetup();
    fakeLhdn($setup['setting'], fn () => Http::response($body, $status));

    app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);

    expect(EInvoiceSubmission::query()->sole()->status)->toBe($expected);
})->with([
    'LHDN server error' => [503, [], EInvoiceStatus::Pending],
    'request timeout' => [408, [], EInvoiceStatus::Pending],
    'duplicate of one LHDN has' => [422, ['error' => ['code' => 'DuplicateSubmission']], EInvoiceStatus::Pending],
    'bad structure' => [400, ['error' => ['code' => 'BadStructure']], EInvoiceStatus::Invalid],
    'not allowed to submit' => [403, ['error' => ['code' => 'IncorrectSubmitter']], EInvoiceStatus::Invalid],
    'throttled' => [429, [], EInvoiceStatus::Invalid],
]);

it('records a failed login as not sent, so it can be sent again', function () {
    $setup = submissionSetup();
    $setup['setting']->update(['api_environment' => EInvoiceEnvironment::Sandbox]);
    Http::fake(['*/connect/token' => Http::response(['error' => 'invalid_client'], 400)]);

    app(SubmitFolioEInvoice::class)($setup['folio'], $setup['owner']);

    $submission = EInvoiceSubmission::query()->sole();
    expect($submission->status)->toBe(EInvoiceStatus::Invalid)
        ->and($submission->submitted_at)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'documentsubmissions'));
});

it('marks a document left Pending without ever being sent as not sent after 2 minutes', function () {
    $setup = submissionSetup();
    $submission = EInvoiceSubmission::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $setup['folio']->id, 'status' => EInvoiceStatus::Pending, 'submitted_at' => null, 'uuid' => null]);

    expect(app(RefreshEInvoiceStatus::class)($submission)->status)->toBe(EInvoiceStatus::Pending);

    $this->travel(3)->minutes();

    expect(app(RefreshEInvoiceStatus::class)($submission->fresh())->status)->toBe(EInvoiceStatus::Invalid);
});

// Credit notes and cancelling

it('refuses a credit note on an e-invoice that was cancelled after the page loaded', function () {
    $setup = submissionSetup();
    $original = submitSetupFolio($setup);
    $stale = $original->fresh();
    $original->update(['status' => EInvoiceStatus::Cancelled]);

    $result = app(IssueEInvoiceCreditNote::class)($stale, 'CN-1', '50.00', 'Discount', $setup['owner']);

    expect($result->refusal)->toBe('original_not_valid')
        ->and(EInvoiceSubmission::query()->where('document_type', EInvoiceDocumentType::CreditNote)->exists())->toBeFalse();
});

it('cancels an e-invoice whose only credit note LHDN rejected', function () {
    $setup = submissionSetup();
    $original = submitSetupFolio($setup);
    EInvoiceSubmission::factory()->create(['property_id' => $setup['property']->id, 'original_submission_id' => $original->id, 'document_type' => EInvoiceDocumentType::CreditNote, 'status' => EInvoiceStatus::Invalid]);

    $result = app(CancelEInvoice::class)($original->fresh(), 'Wrong guest', $setup['owner']);

    expect($result->ok)->toBeTrue()
        ->and($original->fresh()->status)->toBe(EInvoiceStatus::Cancelled);
});

// Monthly summary failures

it('keeps building the other hotels\' monthly summaries when one hotel has a gap in its details', function () {
    $broken = submissionSetup();
    $broken['setting']->update(['consolidation_enabled' => true, 'supplier_city' => null]);
    $broken['property']->forceFill(['city' => null])->save();
    $broken['folio']->payerGuest->update(['tin' => null]);
    $broken['folio']->update(['closed_at' => CarbonImmutable::parse('2026-09-15 10:00', 'UTC')]);

    $working = submissionSetup();
    $working['setting']->update(['consolidation_enabled' => true]);
    $working['folio']->payerGuest->update(['tin' => null]);
    $working['folio']->update(['closed_at' => CarbonImmutable::parse('2026-09-15 10:00', 'UTC')]);

    (new MonthlyConsolidationJob(targetMonth: '2026-09-01'))->handle(app(ConsolidateMonthlyEInvoices::class), app(SummaryMonth::class));

    expect(EInvoiceSubmission::query()->where('property_id', $broken['property']->id)->exists())->toBeFalse()
        ->and(EInvoiceSubmission::query()->where('property_id', $working['property']->id)->where('is_consolidated', true)->count())->toBe(1);
});

it('saves nothing and tells staff when a monthly summary cannot be built', function () {
    $setup = submissionSetup();
    $setup['setting']->update(['consolidation_enabled' => true]);
    $setup['folio']->update(['closed_at' => CarbonImmutable::parse('2026-09-15 10:00', 'UTC')]);
    $setup['folio']->payerGuest->update(['tin' => null]);
    $this->mock(ConsolidateMonthlyEInvoices::class)->shouldReceive('__invoke')->andThrow(new RuntimeException('Broken builder'));

    $this->actingAs($setup['owner'])
        ->post("/p/{$setup['property']->code}/finance/e-invoices/consolidate", ['month' => '2026-09'])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'The monthly summary was not sent.');

    expect(EInvoiceSubmission::query()->exists())->toBeFalse();
});
