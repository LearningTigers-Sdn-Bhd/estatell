<?php

use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\Property;
use App\Services\CityLedger\AgingReport;
use App\Services\CityLedger\StatementBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function reportInvoice(Property $property, Company $company, string $amount, string $dueOn, array $overrides = []): ArInvoice
{
    return ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => $amount, 'due_on' => $dueOn, ...$overrides]);
}

test('invoices fall into buckets by days past due', function (string $dueOn, string $bucket) {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create(['name' => 'Acme']);
    reportInvoice($property, $company, '100.00', $dueOn);

    $report = app(AgingReport::class)->build($property, CarbonImmutable::parse('2026-10-31'));

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->{$bucket})->toBe('100.00')
        ->and($report->rows[0]->total)->toBe('100.00')
        ->and($report->totals->{$bucket})->toBe('100.00');
})->with([
    'due in the future' => ['2026-11-30', 'current'],
    'due today' => ['2026-10-31', 'current'],
    'one day late' => ['2026-10-30', 'days1to30'],
    'thirty days late' => ['2026-10-01', 'days1to30'],
    'thirty one days late' => ['2026-09-30', 'days31to60'],
    'sixty days late' => ['2026-09-01', 'days31to60'],
    'sixty one days late' => ['2026-08-31', 'days61to90'],
    'ninety days late' => ['2026-08-02', 'days61to90'],
    'ninety one days late' => ['2026-08-01', 'over90'],
]);

test('each invoice counts by its own balance and settled, void and credit note rows are left out', function () {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create();
    $partly = reportInvoice($property, $company, '500.00', '2026-10-20');
    ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $partly->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-100.00', 'status' => ArInvoiceStatus::Settled]);
    $payment = ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '150.00']);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $partly->id, 'amount' => '150.00']);
    reportInvoice($property, $company, '999.00', '2026-10-01', ['status' => ArInvoiceStatus::Settled]);
    reportInvoice($property, $company, '999.00', '2026-10-01', ['status' => ArInvoiceStatus::Void]);

    $row = app(AgingReport::class)->build($property, CarbonImmutable::parse('2026-10-31'))->rows[0];

    expect($row->days1to30)->toBe('250.00')
        ->and($row->credit)->toBe('0.00')
        ->and($row->total)->toBe('250.00');
});

test('unallocated payments show as a negative credit and lower the total', function () {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create();
    reportInvoice($property, $company, '300.00', '2026-11-30');
    ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '120.00']);
    ArPayment::factory()->voided()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '999.00']);

    $row = app(AgingReport::class)->build($property, CarbonImmutable::parse('2026-10-31'))->rows[0];

    expect($row->current)->toBe('300.00')
        ->and($row->credit)->toBe('-120.00')
        ->and($row->total)->toBe('180.00');
});

test('a company with only credit is listed and one with nothing is not', function () {
    $property = Property::factory()->create();
    $creditOnly = Company::factory()->for($property)->create(['name' => 'Bravo']);
    Company::factory()->for($property)->create(['name' => 'Empty']);
    $owing = Company::factory()->for($property)->create(['name' => 'Alpha']);
    ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $creditOnly->id, 'amount' => '75.00']);
    reportInvoice($property, $owing, '10.00', '2026-10-30');
    reportInvoice(Property::factory()->create(), Company::factory()->create(), '10.00', '2026-10-30');

    $report = app(AgingReport::class)->build($property, CarbonImmutable::parse('2026-10-31'));

    expect(array_map(fn ($row) => $row->companyName, $report->rows))->toBe(['Alpha', 'Bravo'])
        ->and($report->rows[1]->total)->toBe('-75.00')
        ->and($report->totals->total)->toBe('-65.00')
        ->and($report->totals->companyId)->toBeNull();
});

test('the report and a company row run the same queries however many invoices there are', function () {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create();
    reportInvoice($property, $company, '10.00', '2026-10-01');
    $asAt = CarbonImmutable::parse('2026-10-31');
    app(AgingReport::class)->build($property, $asAt);

    DB::enableQueryLog();
    app(AgingReport::class)->build($property, $asAt);
    $few = count(DB::getQueryLog());

    foreach (range(1, 6) as $number) {
        reportInvoice($property, Company::factory()->for($property)->create(), '10.00', '2026-10-01');
    }

    DB::flushQueryLog();
    app(AgingReport::class)->build($property, $asAt);

    expect(count(DB::getQueryLog()))->toBe($few);
});

test('a company that owes nothing gets a row of zeros', function () {
    $company = Company::factory()->create();

    $row = app(AgingReport::class)->forCompany($company, CarbonImmutable::parse('2026-10-31'));

    expect($row->companyId)->toBe($company->id)
        ->and($row->total)->toBe('0.00');
});

test('a statement lists invoices, credit notes and payments in date order with a running balance', function () {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create();
    $earlier = reportInvoice($property, $company, '1000.00', '2026-09-30', ['issued_on' => '2026-08-31', 'number' => 'F-0']);
    ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '200.00', 'received_on' => '2026-09-15']);
    $invoice = reportInvoice($property, $company, '500.00', '2026-10-30', ['issued_on' => '2026-10-02', 'number' => 'F-1']);
    ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $invoice->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-100.00', 'issued_on' => '2026-10-10', 'number' => 'CN-1', 'reason' => 'Wrong rate', 'status' => ArInvoiceStatus::Settled]);
    ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '300.00', 'received_on' => '2026-10-10', 'receipt_number' => 'R-1', 'reference' => 'TRF-1']);
    ArPayment::factory()->voided()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '777.00', 'received_on' => '2026-10-12']);
    reportInvoice($property, $company, '888.00', '2026-10-30', ['issued_on' => '2026-10-12', 'status' => ArInvoiceStatus::Void]);
    reportInvoice($property, $company, '50.00', '2026-12-30', ['issued_on' => '2026-11-05']);
    ArPayment::factory()->create();

    $statement = app(StatementBuilder::class)->build($company, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect($statement->openingBalance)->toBe('800.00')
        ->and(array_map(fn ($line) => [$line->kind, $line->number, $line->amount, $line->balance], $statement->lines))->toBe([
            ['invoice', 'F-1', '500.00', '1300.00'],
            ['credit_note', 'CN-1', '-100.00', '1200.00'],
            ['payment', 'R-1', '-300.00', '900.00'],
        ])
        ->and($statement->lines[1]->description)->toBe('Wrong rate')
        ->and($statement->closingBalance)->toBe('900.00')
        ->and($statement->from)->toBe('2026-10-01')
        ->and($statement->to)->toBe('2026-10-31');
});

test('a statement with nothing in the range keeps the opening balance as the closing balance', function () {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create();
    reportInvoice($property, $company, '250.00', '2026-09-30', ['issued_on' => '2026-08-31']);

    $statement = app(StatementBuilder::class)->build($company, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect($statement->lines)->toBe([])
        ->and($statement->openingBalance)->toBe('250.00')
        ->and($statement->closingBalance)->toBe('250.00')
        ->and($statement->aging->days31to60)->toBe('250.00');
});

test('the closing balance matches the company AR balance when the range reaches the end', function () {
    $property = Property::factory()->create();
    $company = Company::factory()->for($property)->create();
    $invoice = reportInvoice($property, $company, '400.00', '2026-10-20', ['issued_on' => '2026-10-01']);
    $payment = ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'amount' => '500.00', 'received_on' => '2026-10-05']);
    ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $invoice->id, 'amount' => '400.00']);
    $invoice->update(['status' => ArInvoiceStatus::Settled]);

    $statement = app(StatementBuilder::class)->build($company, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'));

    expect($statement->closingBalance)->toBe('-100.00')
        ->and($statement->aging->credit)->toBe('-100.00')
        ->and($statement->aging->total)->toBe('-100.00');
});
