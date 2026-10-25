<?php

use App\Actions\Onboarding\Wastays\BillWastaysCompanies;
use App\Actions\Onboarding\Wastays\RecordImportLink;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\CityLedger\ArPaymentBalance;
use App\Services\Onboarding\Wastays\WastaysImportTarget;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

/**
 * One finished company stay with an Open Master folio holding a charge, linked to wastays booking 1 and company 8.
 */
function billedStay(string $amount, string $booking = '1'): Folio
{
    $setup = test()->setup;
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);
    $unit = ReservationUnit::factory()->checkedOut()->for($reservation)->create();
    $folio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $reservation->id, 'payer_company_id' => test()->company->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => $amount, 'unit_amount' => $amount, 'posting_date' => '2026-08-13']);
    app(RecordImportLink::class)($setup['property'], 'bookings', $booking, $unit);

    return $folio;
}

/**
 * wastays: invoice 1 of RM 500 for booking 1, a payment of RM 200 allocated to it and a payment of RM 100 left as credit.
 *
 * @return array<string, list<array<string, string|null>>>
 */
function billingTables(string $invoiceAmount = '500.00'): array
{
    return [
        'booking_folios' => [['id' => '1', 'booking_id' => '1']],
        'ar_invoices' => [['id' => '1', 'hotel_corporate_account_id' => '8', 'booking_folio_id' => '1', 'amount' => $invoiceAmount, 'status' => 'open', 'issued_on' => '2026-08-05', 'due_on' => '2026-09-04', 'invoice_reference' => 'SBR-26400001', 'invoice_number' => '1', 'invoice_year' => '2026']],
        'ar_payments' => [
            ['id' => '1', 'hotel_corporate_account_id' => '8', 'amount' => '200.00', 'received_at' => '2026-08-10', 'reference_number' => 'TT-1'],
            ['id' => '2', 'hotel_corporate_account_id' => '8', 'amount' => '100.00', 'received_at' => '2026-08-11', 'reference_number' => 'TT-2'],
        ],
        'ar_payment_allocations' => [['id' => '1', 'ar_payment_id' => '1', 'ar_invoice_id' => '1', 'amount' => '200.00']],
        'ar_payment_allocation_reversals' => [],
        'ar_payment_voids' => [],
    ];
}

function billCompanies(array $tables, bool $dryRun = false)
{
    $path = wastaysDayBundleDir($tables, ['hotel_code' => test()->setup['property']->document_prefix]);
    $target = WastaysImportTarget::resolveForReplay($path, test()->setup['property']->code, test()->setup['admin']->email);

    return app(BillWastaysCompanies::class)($target, $dryRun);
}

beforeEach(function () {
    $this->setup = wastaysReplaySetup();
    PaymentMethod::factory()->create(['property_id' => $this->setup['property']->id, 'type' => PaymentMethodType::OldSystem, 'requires_reference' => true]);
    TransactionCode::factory()->system(SystemTransactionCode::DirectBillPayment)->create(['property_id' => $this->setup['property']->id]);
    $this->company = Company::factory()->for($this->setup['property'])->create();
    app(RecordImportLink::class)($this->setup['property'], 'hotel_corporate_accounts', '8', $this->company);
});

it('settles the folio of an invoice with its wastays number and dates, then adds the payments and keeps the credit', function () {
    $folio = billedStay('500.00');

    $summary = billCompanies(billingTables());

    $invoice = ArInvoice::query()->sole();

    expect($summary->counts)->toMatchArray(['invoices with their wastays number' => 1, 'company payments' => 2, 'payment allocations' => 1])
        ->and($invoice->number)->toBe('SBR-26400001')
        ->and($invoice->issued_on->toDateString())->toBe('2026-08-05')
        ->and($invoice->due_on->toDateString())->toBe('2026-09-04')
        ->and($invoice->migrated)->toBeTrue()
        ->and(app(ArInvoiceBalance::class)->forInvoice($invoice))->toBe('300.00')
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed)
        ->and(ArPayment::query()->get()->map(fn (ArPayment $payment): string => app(ArPaymentBalance::class)->unallocated($payment))->sort()->values()->all())->toBe(['0.00', '100.00'])
        ->and($summary->notes)->toContain('credit on account: 100.00');
});

it('writes nothing on a dry run and nothing twice', function () {
    billedStay('500.00');

    billCompanies(billingTables(), true);

    expect(ArInvoice::query()->count())->toBe(0)->and(ArPayment::query()->count())->toBe(0);

    billCompanies(billingTables());
    $again = billCompanies(billingTables());

    expect($again->counts)->toMatchArray(['already imported' => 1])
        ->and(ArInvoice::query()->count())->toBe(1)
        ->and(ArPayment::query()->count())->toBe(2);
});

it('lists an invoice that no company folio holds exactly and settles the folio that has no invoice', function () {
    billedStay('480.00');

    $summary = billCompanies(billingTables('500.00'));

    expect($summary->counts)->toMatchArray(['wastays invoices with no company folio of that amount' => 1, 'estatell invoices with no wastays invoice' => 1])
        ->and($summary->notes[0])->toContain('SBR-26400001')->toContain('no company folio holding exactly that')
        ->and(ArInvoice::query()->sole()->number)->not->toBe('SBR-26400001');
});

it('applies the surplus credit of an unmatched invoice to the company oldest open invoice', function () {
    billedStay('480.00');
    $tables = billingTables('500.00');
    $tables['ar_payment_allocations'] = [['id' => '1', 'ar_payment_id' => '1', 'ar_invoice_id' => '1', 'amount' => '200.00']];

    $summary = billCompanies($tables);

    expect(ArPayment::query()->get()->sum(fn (ArPayment $payment): float => (float) app(ArPaymentBalance::class)->unallocated($payment)))->toBe(100.0)
        ->and($summary->counts)->toMatchArray(['credit applied to an oldest invoice' => 1]);
});

it('writes nothing when a payment is for a company that was not imported', function () {
    billedStay('500.00');
    $tables = billingTables();
    $tables['ar_payments'][0]['hotel_corporate_account_id'] = '99';

    $summary = billCompanies($tables);

    expect($summary->counts)->toMatchArray(['problems' => 1])->and(ArInvoice::query()->count())->toBe(0)->and(ArPayment::query()->count())->toBe(0);
});
