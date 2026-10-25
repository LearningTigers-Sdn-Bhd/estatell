<?php

use App\Actions\Onboarding\Wastays\ImportWastaysBalances;
use App\Actions\Onboarding\Wastays\RecordImportLink;
use App\Enums\ArInvoiceStatus;
use App\Enums\PaymentMethodType;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\Company;
use App\Models\ImportLink;
use App\Models\PaymentMethod;
use App\Services\CityLedger\ArInvoiceBalance;
use App\Services\Onboarding\Wastays\WastaysImportTarget;

require_once __DIR__.'/../Support/WastaysReplaySetup.php';

/**
 * Five invoices against the go-live date 13 Aug: part paid with one allocation reversed, fully paid, void, due before it
 * was issued, and one for a company that was not imported. Three payments: part used, voided, and used up.
 *
 * @return array<string, list<array<string, string|null>>>
 */
function wastaysBalanceTables(): array
{
    $invoice = fn (string $id, string $amount, string $status, string $issued, string $due, string $account = '8'): array => [
        'id' => $id, 'hotel_corporate_account_id' => $account, 'amount' => $amount, 'status' => $status, 'issued_on' => $issued, 'due_on' => $due,
        'invoice_reference' => "SBR-26400{$id}", 'invoice_number' => $id, 'invoice_year' => '2026',
        'paid_amount' => '999.00', 'outstanding_amount' => '999.00',
    ];
    $payment = fn (string $id, string $amount, string $reference): array => [
        'id' => $id, 'hotel_corporate_account_id' => '8', 'amount' => $amount, 'received_at' => '2026-08-01', 'reference_number' => $reference,
    ];

    return [
        'ar_invoices' => [
            $invoice('1', '1000.00', 'partially_paid', '2026-08-01', '2026-08-31'),
            $invoice('2', '500.00', 'paid', '2026-08-01', '2026-08-31'),
            $invoice('3', '700.00', 'void', '2026-08-01', '2026-08-31'),
            $invoice('4', '300.00', 'open', '2026-08-05', '2026-08-01'),
            $invoice('5', '200.00', 'open', '2026-08-05', '2026-08-31', '99'),
        ],
        'ar_payments' => [
            $payment('1', '900.00', 'TT-1'),
            $payment('2', '11400.00', 'TT-2'),
            $payment('3', '500.00', 'TT-3'),
            $payment('4', '460.00', 'TT-4'),
        ],
        'ar_payment_allocations' => [
            ['id' => '1', 'ar_payment_id' => '1', 'ar_invoice_id' => '1', 'amount' => '400.00'],
            ['id' => '2', 'ar_payment_id' => '1', 'ar_invoice_id' => '1', 'amount' => '100.00'],
            ['id' => '3', 'ar_payment_id' => '3', 'ar_invoice_id' => '2', 'amount' => '500.00'],
        ],
        'ar_payment_allocation_reversals' => [['id' => '1', 'ar_payment_allocation_id' => '2']],
        'ar_payment_voids' => [['id' => '1', 'ar_payment_id' => '2']],
    ];
}

beforeEach(function () {
    $this->setup = wastaysReplaySetup();
    PaymentMethod::factory()->create(['property_id' => $this->setup['property']->id, 'type' => PaymentMethodType::OldSystem, 'requires_reference' => true]);
    $this->company = Company::factory()->for($this->setup['property'])->create();
    app(RecordImportLink::class)($this->setup['property'], 'hotel_corporate_accounts', '8', $this->company);
});

function runBalanceImport(array $tables, bool $dryRun = false)
{
    $path = wastaysDayBundleDir($tables, ['hotel_code' => test()->setup['property']->document_prefix]);
    $target = WastaysImportTarget::resolveForReplay($path, test()->setup['property']->code, test()->setup['admin']->email);

    return app(ImportWastaysBalances::class)($target, $dryRun);
}

it('imports what is still owed and the credit left, from allocations that were not reversed', function () {
    $summary = runBalanceImport(wastaysBalanceTables());

    expect($summary->counts)->toMatchArray([
        'unpaid invoices' => 2,
        'void invoices left in wastays' => 1,
        'invoices fully paid, left in wastays' => 1,
        'due dates before the issue date moved to the issue date' => 1,
        'rows that could not be imported' => 1,
        'credit on account' => 2,
        'voided payments left in wastays' => 1,
        'payments fully used, left in wastays' => 1,
    ])->and($summary->notes)->toContain('invoice SBR-264005: import the companies first.')
        ->and($summary->notes)->toContain('invoice SBR-264001 owed 600.00');

    $part = ArInvoice::query()->where('number', 'SBR-264001')->sole();
    $moved = ArInvoice::query()->where('number', 'SBR-264004')->sole();

    expect($part->amount)->toBe('600.00')
        ->and($part->migrated)->toBeTrue()
        ->and($part->status)->toBe(ArInvoiceStatus::Open)
        ->and(app(ArInvoiceBalance::class)->forInvoice($part))->toBe('600.00')
        ->and($moved->due_on->toDateString())->toBe('2026-08-05')
        ->and(ArPayment::query()->orderBy('reference')->get()->map(fn (ArPayment $payment): array => [$payment->reference, $payment->amount, $payment->receipt_number])->all())
        ->toBe([['TT-1', '500.00', null], ['TT-4', '460.00', null]]);
});

it('imports once, and writes nothing on a dry run', function () {
    runBalanceImport(wastaysBalanceTables(), true);

    expect(ArInvoice::query()->count())->toBe(0)->and(ArPayment::query()->count())->toBe(0)->and(ImportLink::query()->where('source_table', 'like', 'ar_%')->count())->toBe(0);

    runBalanceImport(wastaysBalanceTables());
    $again = runBalanceImport(wastaysBalanceTables());

    expect($again->counts)->toMatchArray(['already imported' => 4])
        ->and(ArInvoice::query()->count())->toBe(2)
        ->and(ArPayment::query()->count())->toBe(2);
});
