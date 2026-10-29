<?php

use Tests\Browser\Docs\Companies\ArInvoicesAndPayments\ArFixture;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the settle button', function () {
    $retreat = $this->scenario('G2');
    ArFixture::advance($this->property, $retreat, '200.00');
    ArFixture::checkOutRetreat($retreat);

    $page = $this->open(route('reservations.folio', [$this->property, $retreat]))
        ->waitForText('Settle to City ledger')
        ->wait(1);

    $this->shoot($page, 'settle-button', badges: ['Settle to City ledger']);
});

test('the settle dialog', function () {
    $retreat = $this->scenario('G2');
    ArFixture::advance($this->property, $retreat, '200.00');
    ArFixture::checkOutRetreat($retreat);

    $page = $this->open(route('reservations.folio', [$this->property, $retreat]))
        ->click('button:has-text("Settle to City ledger")')
        ->waitForText('You cannot reopen the folio')
        ->wait(1);

    $this->shoot($page, 'settle-dialog', frame: '[role="alertdialog"]');
});

test('the settle dialog warns when the company is over its limit', function () {
    $retreat = $this->scenario('G2');
    ArFixture::checkOutRetreat($retreat);
    ArFixture::company($this->property)->update(['credit_limit' => '1000.00']);

    $page = $this->open(route('reservations.folio', [$this->property, $retreat]))
        ->click('button:has-text("Settle to City ledger")')
        ->waitForText('is over its credit limit')
        ->wait(1);

    $this->shoot($page, 'settle-over-limit', frame: '[role="alertdialog"]');
});

test('settling a folio', function () {
    $retreat = $this->scenario('G2');
    ArFixture::advance($this->property, $retreat, '200.00');
    ArFixture::checkOutRetreat($retreat);

    $page = $this->open(route('reservations.folio', [$this->property, $retreat]))
        ->click('button:has-text("Settle to City ledger")')
        ->waitForText('You cannot reopen the folio')
        ->click('[role="alertdialog"] button[type="submit"]')
        ->waitForText('Billed to Borneo Ventures')
        ->wait(1);

    $this->shoot($page, 'settle-done', badges: ['Invoice'], highlight: ['[role="alert"]']);
});

test('settle is refused while a room is in house', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G2')]))
        ->waitForText('Settle to City ledger')
        ->click('button:has-text("Settle to City ledger")')
        ->waitForText('You cannot reopen the folio')
        ->click('[role="alertdialog"] button[type="submit"]')
        ->waitForText('still in-house')
        ->wait(1);

    $this->shoot($page, 'settle-refused');
});
