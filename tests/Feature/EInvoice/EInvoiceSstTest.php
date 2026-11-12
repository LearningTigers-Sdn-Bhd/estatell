<?php

require_once __DIR__.'/../Support/EInvoiceSetup.php';

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Actions\EInvoice\IssueEInvoiceCreditNote;
use App\Actions\EInvoice\SubmitArEInvoice;
use App\Enums\ArInvoiceType;
use App\Enums\EInvoiceDocumentType;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\EInvoiceSubmission;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Tax;
use App\Services\EInvoice\MockMyInvoisClient;
use Carbon\CarbonImmutable;

afterEach(fn () => MockMyInvoisClient::resetOverrides());

/**
 * Add SST 8% (RM28) and tourism tax (RM10) to the setup folio's RM350 room, plus an untaxed extra of `$untaxed`
 * when given. Ledger lines cannot change, so the room keeps its amount.
 */
function sstStay(array $setup, ?string $untaxed = null): Folio
{
    $folio = $setup['folio'];
    $room = $folio->lines()->first();
    $sst = Tax::factory()->for($setup['property'])->create(['code' => 'SST', 'rate' => '8.00']);
    $tourism = Tax::factory()->for($setup['property'])->create(['code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00']);
    $setup['setting']->update(['supplier_ttx_registration' => '141-2017-10000123']);

    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $sst->transaction_code_id, 'kind' => TransactionKind::Tax, 'amount' => '28.00', 'unit_amount' => '28.00', 'parent_line_id' => $room->id, 'description' => 'SST 8%']);
    FolioLine::factory()->for($folio)->create(['transaction_code_id' => $tourism->transaction_code_id, 'kind' => TransactionKind::Tax, 'amount' => '10.00', 'unit_amount' => '10.00', 'parent_line_id' => $room->id, 'description' => 'Tourism tax']);

    if ($untaxed !== null) {
        FolioLine::factory()->for($folio)->create(['transaction_code_id' => $room->transaction_code_id, 'kind' => TransactionKind::Charge, 'amount' => $untaxed, 'unit_amount' => $untaxed, 'description' => 'Boat transfer']);
    }

    return $folio->fresh();
}

/**
 * The tax subtotals of a document or a line, by tax type: [taxable, tax, percent].
 *
 * @return array<string, list<float|null>>
 */
function sstSubtotals(array $taxTotal): array
{
    return collect($taxTotal['TaxSubtotal'])->mapWithKeys(fn (array $subtotal): array => [
        $subtotal['TaxCategory'][0]['ID'][0]['_'] => [$subtotal['TaxableAmount'][0]['_'], $subtotal['TaxAmount'][0]['_'], $subtotal['Percent'][0]['_'] ?? null],
    ])->all();
}

function sstCreditNote(EInvoiceSubmission $original, string $amount, string $number = 'CN-1'): EInvoiceSubmission|string
{
    $result = app(IssueEInvoiceCreditNote::class)($original->fresh(), $number, $amount, 'Room discount', $original->property->users()->first());

    return $result->ok ? $result->submission : (string) $result->message;
}

it('reports a City ledger stay with its own charges, SST and tourism tax, not as exempt', function () {
    $setup = submissionSetup();
    $folio = sstStay($setup);
    $company = Company::factory()->for($setup['property'])->create(['tin' => 'C9876543210', 'brn' => '198201002233', 'billing_city' => 'Kuala Lumpur']);
    $folio->update(['payer_guest_id' => null, 'payer_company_id' => $company->id, 'invoice_number' => 'INV-77']);
    $invoice = ArInvoice::factory()->for($setup['property'])->create(['company_id' => $company->id, 'folio_id' => $folio->id, 'number' => 'INV-77', 'type' => ArInvoiceType::Invoice, 'amount' => '388.00']);

    $document = app(SubmitArEInvoice::class)($invoice, $setup['owner'])->submission->document_payload['Invoice'][0];

    expect($document['ID'][0]['_'])->toBe('INV-77')
        ->and(sstSubtotals($document['InvoiceLine'][0]['TaxTotal'][0]))->toEqual(['02' => [350, 28, 8], '03' => [350, 10, null]])
        ->and(sstSubtotals($document['TaxTotal'][0]))->toEqual(['02' => [350, 28, null], '03' => [350, 10, null]])
        ->and($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(388)
        ->and($document['AccountingCustomerParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0]['_'])->toBe('C9876543210');
});

it('refuses to send a City ledger credit note, a migrated invoice or one with no stay', function (array $attributes) {
    $setup = submissionSetup();
    $company = Company::factory()->for($setup['property'])->create(['tin' => 'C9876543210', 'brn' => '198201002233', 'billing_city' => 'Kuala Lumpur']);
    $billed = ArInvoice::factory()->for($setup['property'])->create(['company_id' => $company->id, 'folio_id' => $setup['folio']->id]);
    $invoice = $attributes === ['type' => 'credit']
        ? ArInvoice::factory()->for($setup['property'])->create(['company_id' => $company->id, 'type' => ArInvoiceType::CreditNote, 'amount' => '-50.00', 'credit_for_invoice_id' => $billed->id, 'folio_id' => null])
        : tap($billed)->update($attributes);

    $result = app(SubmitArEInvoice::class)($invoice, $setup['owner']);

    expect($result->refusal)->toBe('nothing_to_report')
        ->and(EInvoiceSubmission::query()->exists())->toBeFalse();
})->with([
    'credit note' => [['type' => 'credit']],
    'migrated' => [['migrated' => true]],
    'no stay' => [['folio_id' => null]],
]);

it('shows the SST rate on each line of a stay e-invoice', function () {
    $setup = submissionSetup();
    sstStay($setup);

    $document = submitSetupFolio($setup)->document_payload['Invoice'][0];

    expect(sstSubtotals($document['InvoiceLine'][0]['TaxTotal'][0]))->toEqual(['02' => [350, 28, 8], '03' => [350, 10, null]]);
});

it('reports SST in a monthly summary on the charges it is on, with the rate, and the rest as exempt', function () {
    $setup = submissionSetup();
    $setup['setting']->update(['consolidation_enabled' => true]);
    $folio = sstStay($setup, '50.00');
    $folio->payerGuest->update(['tin' => null]);
    $folio->update(['closed_at' => CarbonImmutable::parse('2026-09-15 10:00', 'UTC')]);

    $document = app(ConsolidateMonthlyEInvoices::class)($setup['property'], CarbonImmutable::parse('2026-09-01'))[0]->document_payload['Invoice'][0];

    expect($document['InvoiceLine'][0]['LineExtensionAmount'][0]['_'])->toEqual(400)
        ->and(sstSubtotals($document['InvoiceLine'][0]['TaxTotal'][0]))->toEqual(['02' => [350, 28, 8], '03' => [350, 10, null], 'E' => [50, 0, null]])
        ->and(sstSubtotals($document['TaxTotal'][0]))->toEqual(['02' => [350, 28, null], '03' => [350, 10, null], 'E' => [50, 0, null]])
        ->and($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(438);
});

it('takes SST off a part credit in proportion, shows the rate, and leaves the tourism tax', function () {
    $setup = submissionSetup();
    sstStay($setup);
    $original = submitSetupFolio($setup);

    $note = sstCreditNote($original, '108.00');
    $document = $note->document_payload['Invoice'][0];

    expect($note->document_type)->toBe(EInvoiceDocumentType::CreditNote)
        ->and($document['InvoiceLine'])->toHaveCount(1)
        ->and($document['InvoiceLine'][0]['LineExtensionAmount'][0]['_'])->toEqual(100)
        ->and(sstSubtotals($document['InvoiceLine'][0]['TaxTotal'][0]))->toEqual(['02' => [100, 8, 8]])
        ->and(sstSubtotals($document['TaxTotal'][0]))->toEqual(['02' => [100, 8, null]])
        ->and($document['LegalMonetaryTotal'][0]['TaxExclusiveAmount'][0]['_'])->toEqual(100)
        ->and($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(108);
});

it('splits a part credit between SST charges and untaxed charges, to the sen', function () {
    $setup = submissionSetup();
    sstStay($setup, '50.00');
    $original = submitSetupFolio($setup);

    // 428 creditable: 378 with SST and 50 without. RM10 takes 8.83 with SST (8.18 + 0.65) and 1.17 without.
    $document = sstCreditNote($original, '10.00')->document_payload['Invoice'][0];

    expect(sstSubtotals($document['InvoiceLine'][0]['TaxTotal'][0]))->toEqual(['02' => [8.18, 0.65, 8]])
        ->and(sstSubtotals($document['InvoiceLine'][1]['TaxTotal'][0]))->toEqual(['E' => [1.17, 0, null]])
        ->and($document['LegalMonetaryTotal'][0]['TaxExclusiveAmount'][0]['_'])->toEqual(9.35)
        ->and($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(10);
});

it('takes tourism tax off only with the whole remaining amount, and then exactly what is left', function () {
    $setup = submissionSetup();
    sstStay($setup);
    $original = submitSetupFolio($setup);
    sstCreditNote($original, '108.00', 'CN-1');

    expect(sstCreditNote($original, '271.00', 'CN-2'))->toBe('A credit note can take off at most 270.00, or all 280.00 that is left. Tourism tax is charged per room-night, so it only comes off when the whole amount is credited.')
        ->and(sstCreditNote($original, '281.00', 'CN-2'))->toBe('A credit note can take off at most 280.00, what is left on this e-invoice.');

    $document = sstCreditNote($original, '280.00', 'CN-2')->document_payload['Invoice'][0];

    expect(sstSubtotals($document['InvoiceLine'][0]['TaxTotal'][0]))->toEqual(['02' => [250, 20, 8], '03' => [250, 10, null]])
        ->and($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(280)
        ->and(sstCreditNote($original, '0.01', 'CN-3'))->toBe('A credit note can take off at most 0.00, what is left on this e-invoice.');
});

it('credits what is left exactly after part credits, so nothing is lost to rounding', function () {
    $setup = submissionSetup();
    sstStay($setup, '50.00');
    $original = submitSetupFolio($setup);
    sstCreditNote($original, '10.00', 'CN-1');
    sstCreditNote($original, '33.33', 'CN-2');

    // 428 - 43.33 = 384.67 creditable, plus RM10 tourism tax.
    $document = sstCreditNote($original, '394.67', 'CN-3')->document_payload['Invoice'][0];
    $notes = EInvoiceSubmission::query()->where('original_submission_id', $original->id)->get();
    $sum = fn (string $type, int $field): float => round($notes->sum(fn (EInvoiceSubmission $note): float => collect($note->document_payload['Invoice'][0]['TaxTotal'][0]['TaxSubtotal'])
        ->filter(fn (array $subtotal): bool => $subtotal['TaxCategory'][0]['ID'][0]['_'] === $type)
        ->sum(fn (array $subtotal): float => $field === 0 ? $subtotal['TaxableAmount'][0]['_'] : $subtotal['TaxAmount'][0]['_'])), 2);

    expect($document['LegalMonetaryTotal'][0]['PayableAmount'][0]['_'])->toEqual(394.67)
        ->and($sum('02', 0))->toEqual(350.0)
        ->and($sum('02', 1))->toEqual(28.0)
        ->and($sum('E', 0))->toEqual(50.0)
        ->and($sum('03', 1))->toEqual(10.0);
});

it('gives a note on a monthly summary the consolidated classification, as LHDN requires for the general public buyer', function () {
    $setup = submissionSetup();
    $setup['setting']->update(['consolidation_enabled' => true]);
    $folio = sstStay($setup);
    $folio->payerGuest->update(['tin' => null]);
    $folio->update(['closed_at' => CarbonImmutable::parse('2026-09-15 10:00', 'UTC')]);
    $summary = app(ConsolidateMonthlyEInvoices::class)($setup['property'], CarbonImmutable::parse('2026-09-01'))[0];

    $note = sstCreditNote($summary, '108.00');
    $stayNote = sstCreditNote(submitSetupFolio(submissionSetup()), '10.00');
    $note = $note->document_payload['Invoice'][0];
    $stayNote = $stayNote->document_payload['Invoice'][0];

    expect($note['InvoiceLine'][0]['Item'][0]['CommodityClassification'][0]['ItemClassificationCode'][0]['_'])->toBe('004')
        ->and($note['AccountingCustomerParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0]['_'])->toBe('EI00000000010')
        ->and($stayNote['InvoiceLine'][0]['Item'][0]['CommodityClassification'][0]['ItemClassificationCode'][0]['_'])->toBe('022');
});
