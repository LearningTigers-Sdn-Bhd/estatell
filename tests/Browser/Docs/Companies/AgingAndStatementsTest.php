<?php

use App\Actions\CityLedger\RecordArPayment;
use App\Data\CityLedger\NewArPaymentData;
use App\Enums\PaymentMethodType;
use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Open invoices of different ages for Borneo Ventures and two more companies, and one payment nobody has allocated yet.
 * The business date is 14 Oct 2026. Returns the Borneo Ventures company.
 */
function agingAndStatementsOwe(Property $property): Company
{
    $borneo = Company::query()->where('property_id', $property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();
    $sabah = Company::factory()->create(['property_id' => $property->id, 'name' => 'Sabah Tours Sdn Bhd']);
    $kota = Company::factory()->create(['property_id' => $property->id, 'name' => 'Kota Logistics Sdn Bhd']);

    $invoices = [
        [$borneo, 'AR-0001', '2026-05-02', '2026-06-01', '1200.00'],
        [$borneo, 'AR-0002', '2026-08-11', '2026-09-10', '2400.00'],
        [$borneo, 'AR-0003', '2026-09-20', '2026-10-05', '1800.00'],
        [$borneo, 'AR-0004', '2026-10-09', '2026-11-08', '3000.00'],
        [$sabah, 'AR-0005', '2026-07-01', '2026-07-31', '950.00'],
        [$sabah, 'AR-0006', '2026-09-25', '2026-10-10', '600.00'],
        [$sabah, 'AR-0007', '2026-10-10', '2026-11-09', '1450.00'],
        [$kota, 'AR-0008', '2026-10-01', '2026-10-31', '700.00'],
    ];

    foreach ($invoices as [$company, $number, $issued, $due, $amount]) {
        ArInvoice::factory()->create([
            'property_id' => $property->id,
            'company_id' => $company->id,
            'created_by' => auth()->id(),
            'number' => $number,
            'issued_on' => $issued,
            'due_on' => $due,
            'amount' => $amount,
        ]);
    }

    $method = PaymentMethod::query()->where('property_id', $property->id)->where('type', PaymentMethodType::BankTransfer)->where('active', true)->firstOrFail();

    app(RecordArPayment::class)(auth()->user(), $kota, new NewArPaymentData(
        $method->id,
        '1000.00',
        (string) Str::uuid(),
        CarbonImmutable::parse('2026-10-12'),
        'BT-KOTA-77',
    ));

    return $borneo;
}

test('the aging report', function () {
    agingAndStatementsOwe($this->property);

    $page = $this->open(route('finance.city-ledger.aging', $this->property), height: 800)
        ->waitForText('Sabah Tours Sdn Bhd')
        ->wait(1);

    $this->shoot($page, 'aging-and-statements-aging', badges: ['As at', 'Quick date', 'Download CSV', 'Download PDF']);
});

test('the aging report as at an earlier date', function () {
    agingAndStatementsOwe($this->property);

    $page = $this->open(route('finance.city-ledger.aging', [$this->property, 'as_at' => '2026-09-30']), height: 800)
        ->waitForText('Sabah Tours Sdn Bhd')
        ->wait(1);

    $this->shoot($page, 'aging-and-statements-aging-earlier', badges: ['As at']);
});

test('the company statement', function () {
    $borneo = agingAndStatementsOwe($this->property);

    $page = $this->open(route('finance.city-ledger.companies.statement', [$this->property, $borneo, 'from' => '2026-09-01', 'to' => '2026-10-14']), height: 1000)
        ->waitForText('Closing balance')
        ->wait(1);

    $this->shoot($page, 'aging-and-statements-statement', badges: ['Period', 'Quick range', 'Download PDF', 'Opening balance', 'Closing balance']);
});
