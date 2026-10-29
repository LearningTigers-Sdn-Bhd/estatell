<?php

use App\Enums\PaymentMethodType;
use Tests\Browser\Docs\Companies\ArInvoicesAndPayments\ArFixture;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the issue credit note button', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $second]))
        ->waitForText('Folio lines')
        ->wait(1);

    $this->shoot($page, 'credit-note-button', badges: ['Issue credit note']);
});

test('the credit note dialog', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $second]))
        ->click('button:has-text("Issue credit note")')
        ->waitForText('Corrects invoice')
        ->type('#credit-reason', 'Minibar charged twice.')
        ->wait(1);

    $this->shoot($page, 'credit-note-dialog', badges: ['Amount', 'Reason'], frame: '[role="dialog"]');
});

test('the credit note dialog when a payment is allocated', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '30.00', 'MBB-90001'), [$second->id => '30.00']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $second]))
        ->click('button:has-text("Issue credit note")')
        ->waitForText('To credit more, reverse a payment first')
        ->wait(1);

    $this->shoot($page, 'credit-note-allocated', frame: '[role="dialog"]');
});

test('a credit note for more than the balance is refused', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '30.00', 'MBB-90001'), [$second->id => '30.00']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $second]))
        ->click('button:has-text("Issue credit note")')
        ->waitForText('Corrects invoice');

    $page->fill('#credit-amount', '85.00');
    $page->type('#credit-reason', 'Minibar charged twice.')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('The credit cannot be more than')
        ->wait(1);

    $this->shoot($page, 'credit-note-too-much', frame: '[role="dialog"]');
});

test('the credit note dialog of a paid invoice', function () {
    [$first] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::allocate(ArFixture::pay(ArFixture::company($this->property), PaymentMethodType::BankTransfer, '1517.96', 'MBB-88231'), [$first->id => '1517.96']);

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $first]))
        ->click('button:has-text("Issue credit note")')
        ->waitForText('This invoice is paid')
        ->wait(1);

    $this->shoot($page, 'credit-note-paid', badges: ['Reverse'], frame: '[role="dialog"]');
});

test('an invoice with a credit note', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    ArFixture::credit($second, '35.00', 'Minibar charged twice.');

    $page = $this->open(route('finance.city-ledger.invoices.show', [$this->property, $second]))
        ->waitForText('Folio lines')
        ->wait(1);

    $this->shoot($page, 'credit-note-on-invoice', badges: ['Credit notes'], highlight: ['section:first-of-type']);
});

test('the credit note page and its void dialog', function () {
    [, $second] = ArFixture::twoInvoices($this->property, $this->scenario('G2'));
    $note = ArFixture::credit($second, '35.00', 'Minibar charged twice.');
    $url = route('finance.city-ledger.invoices.show', [$this->property, $note]);

    $this->shoot($this->open($url)->waitForText('Corrects invoice')->wait(1), 'credit-note-page', badges: ['Void']);

    $page = $this->open($url)
        ->click('button:text-is("Void")')
        ->waitForText('The credit is taken back')
        ->wait(1);

    $this->shoot($page, 'credit-note-void-dialog', frame: '[role="alertdialog"]');
});
