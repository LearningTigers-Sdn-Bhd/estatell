<?php

use App\Actions\CityLedger\AddOldSystemCredit;
use App\Actions\CityLedger\AddOldSystemInvoice;
use App\Actions\CityLedger\CreateCompany;
use App\Enums\CompanyType;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $this->acme = app(CreateCompany::class)(auth()->user(), $this->property, [
        'name' => 'Acme Sdn Bhd',
        'type' => CompanyType::Corporate,
        'credit_limit' => '5000.00',
        'payment_terms_days' => 30,
        'direct_bill_enabled' => true,
    ]);

    $this->openDialog = function (Company $company): AwaitableWebpage {
        return $this->open(route('finance.city-ledger.companies.show', [$this->property, $company]), 1000)
            ->waitForText('More actions')
            ->click('More actions')
            ->click('[role="menuitem"]:has-text("Add from old system")')
            ->waitForText('Bring a balance from the old PMS into the city ledger.');
    };

    $this->pickDate = function (AwaitableWebpage $page, string $field, string $day): AwaitableWebpage {
        return $page->click($field)
            ->wait(1)
            ->click('[data-day="'.$day.'"] button')
            ->wait(1);
    };
});

test('the more actions menu', function () {
    $page = $this->open(route('finance.city-ledger.companies.show', [$this->property, $this->acme]))
        ->waitForText('More actions')
        ->click('More actions')
        ->waitForText('Add from old system')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-menu', badges: ['Add from old system', 'Suspend']);
});

test('an unpaid invoice', function () {
    $page = ($this->openDialog)($this->acme);

    $this->shoot($page, 'old-system-balances-invoice-empty', badges: ['Unpaid invoice', 'Credit on account'], frame: '[role="dialog"]');

    $page->type('#old-number', 'INV-2026-0912');
    ($this->pickDate)($page, '#old-issued', '2026-10-02');
    ($this->pickDate)($page, '#old-due', '2026-10-12');
    $page->type('#old-amount', '4850.00')
        ->type('#old-reason', 'Migrated from old PMS')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-invoice', badges: ['Old invoice no.', 'Issued on', 'Due on', 'Amount owed', 'Reason', 'Save'], frame: '[role="dialog"]');
});

test('credit on account', function () {
    $page = ($this->openDialog)($this->acme);
    $page->click('label:has-text("Credit on account")')
        ->waitForText('Old receipt no.')
        ->type('#old-reference', 'OR-2026-0345');
    ($this->pickDate)($page, '#old-received', '2026-10-05');
    $page->type('#old-amount', '1200.00')
        ->type('#old-reason', 'Advance paid in the old PMS')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-credit', badges: ['Old receipt no.', 'Received on', 'Amount'], frame: '[role="dialog"]');
});

test('over the credit limit', function () {
    $page = ($this->openDialog)($this->acme);
    $page->type('#old-number', 'INV-2026-0913')
        ->type('#old-amount', '5850.00')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-over-limit', frame: '[role="dialog"]');
});

test('an invoice issued after go-live', function () {
    $page = ($this->openDialog)($this->acme);
    $page->type('#old-number', 'INV-2026-0914');
    ($this->pickDate)($page, '#old-issued', '2026-10-10');
    ($this->pickDate)($page, '#old-due', '2026-10-20');
    $page->type('#old-amount', '900.00')
        ->type('#old-reason', 'Migrated from old PMS')
        ->click('[role="dialog"] button:text-is("Save")')
        ->waitForText('The old invoice must be issued before the go-live date.')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-after-go-live', frame: '[role="dialog"]');
});

test('the old invoice in the invoice list', function () {
    app(AddOldSystemInvoice::class)(auth()->user(), $this->acme, 'INV-2026-0912', CarbonImmutable::parse('2026-10-02'), CarbonImmutable::parse('2026-10-12'), '4850.00', 'Migrated from old PMS');

    $page = $this->open(route('finance.city-ledger.invoices.index', $this->property))
        ->waitForText('INV-2026-0912')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-invoices', badges: ['Migrated']);
});

test('the old credit on the payments tab', function () {
    app(AddOldSystemCredit::class)(auth()->user(), $this->acme, 'OR-2026-0345', CarbonImmutable::parse('2026-10-05'), '1200.00', 'Advance paid in the old PMS', (string) Str::uuid());

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $this->acme]))
        ->waitForText('OR-2026-0345')
        ->wait(1);

    $this->shoot($page, 'old-system-balances-payments');
});
