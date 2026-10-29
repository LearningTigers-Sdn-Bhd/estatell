<?php

use App\Enums\PaymentMethodType;
use Tests\Browser\Docs\Companies\ArInvoicesAndPayments\ArFixture;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the allocate item in the row menu', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '1602.96', 'MBB-88231');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]))
        ->waitForText('MBB-88231')
        ->click('button[aria-label^="Actions for"]')
        ->waitForText('Allocate')
        ->wait(1);

    $this->shoot($page, 'allocate-row-menu', badges: ['Allocate']);
});

test('the allocate dialog', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '1000.00', 'MBB-88231');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]), height: 1000)
        ->waitForText('MBB-88231')
        ->click('button[aria-label^="Actions for"]')
        ->click('[role="menuitem"]:has-text("Allocate")')
        ->waitForText('left to allocate');

    $page->fill('input[id^="alloc-"] >> nth=0', '85.00')
        ->fill('input[id^="alloc-"] >> nth=1', '915.00')
        ->wait(1);

    $this->shoot($page, 'allocate-dialog', frame: '[role="dialog"]');
});

test('the allocate dialog is refused when the amounts are too high', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '1000.00', 'MBB-88231');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]), height: 1000)
        ->waitForText('MBB-88231')
        ->click('button[aria-label^="Actions for"]')
        ->click('[role="menuitem"]:has-text("Allocate")')
        ->waitForText('left to allocate');

    $page->fill('input[id^="alloc-"] >> nth=0', '85.00')
        ->fill('input[id^="alloc-"] >> nth=1', '1517.96')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('more than the payment has left')
        ->wait(1);

    $this->shoot($page, 'allocate-refused', frame: '[role="dialog"]');
});

test('the reverse button on an invoice', function () {
    $company = ArFixture::company($this->property);
    [$first] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay($company, PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231'), [$first->id => '1517.96']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $first]))
        ->waitForText('Folio lines')
        ->wait(1);

    $this->shoot($page, 'reverse-button', badges: ['Reverse'], highlight: ['section:nth-of-type(2)']);
});

test('the reverse allocation dialog', function () {
    $company = ArFixture::company($this->property);
    [$first] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay($company, PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231'), [$first->id => '1517.96']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $first]))
        ->waitForText('Folio lines')
        ->click('button:text-is("Reverse")')
        ->waitForText('Reverse allocation')
        ->type('#reverse-reason', 'The transfer was for another invoice.')
        ->wait(1);

    $this->shoot($page, 'reverse-dialog', badges: ['Reason'], frame: '[role="dialog"]');
});

test('the invoice after a reversal', function () {
    $company = ArFixture::company($this->property);
    [$first] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay($company, PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231'), [$first->id => '1517.96']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $first]))
        ->waitForText('Folio lines')
        ->click('button:text-is("Reverse")')
        ->waitForText('Reverse allocation')
        ->type('#reverse-reason', 'The transfer was for another invoice.')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Reversed')
        ->wait(1);

    $this->shoot($page, 'reverse-done', badges: ['Reversed'], highlight: ['section:nth-of-type(2)']);
});

test('the void payment dialog', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '150.00', 'MBB-88231');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]))
        ->waitForText('MBB-88231')
        ->click('button[aria-label^="Actions for"]')
        ->click('[role="menuitem"]:has-text("Void")')
        ->waitForText('Use this for a payment recorded by mistake')
        ->wait(1);

    $this->shoot($page, 'void-payment-dialog', frame: '[role="alertdialog"]');
});

test('a voided payment', function () {
    $company = ArFixture::company($this->property);
    ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::pay($company, PaymentMethodType::BankTransfer, '150.00', 'MBB-88231');

    $page = $this->open(route('finance.city-ledger.companies.payments', [$this->property, $company]))
        ->waitForText('MBB-88231')
        ->click('button[aria-label^="Actions for"]')
        ->click('[role="menuitem"]:has-text("Void")')
        ->waitForText('Use this for a payment recorded by mistake')
        ->click('[role="alertdialog"] button[type="submit"]')
        ->waitForText('Voided')
        ->wait(1);

    $this->shoot($page, 'void-payment-done', badges: ['Voided']);
});
