<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the print menu of a closed folio', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S1')]))
        ->waitForText('Reopen folio')
        ->click('button:text-is("Print")')
        ->waitForText('Print voucher')
        ->wait(1);

    $this->shoot($page, 'invoices-print-menu', badges: ['Print voucher', 'Print invoice']);
});

test('the print menu of an open folio', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Projected total')
        ->click('button:text-is("Print")')
        ->waitForText('Print voucher')
        ->wait(1);

    $this->shoot($page, 'invoices-print-menu-open', badges: ['Print voucher']);
});

test('the print voucher dialog', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G1')]))
        ->waitForText('Projected total')
        ->click('button:text-is("Print")')
        ->click('[role="menuitem"]:has-text("Print voucher")')
        ->waitForText('Choose the posted lines to print.')
        ->click('[role="dialog"] button:text-is("Charge")')
        ->wait(1);

    $this->shoot($page, 'invoices-voucher', badges: ['Quick select', 'Charge', 'Print voucher'], frame: '[role="dialog"]');
});

test('the print voucher item in a row menu', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->waitForText('Projected total')
        ->click('button[aria-label^="Actions for Breakfast"] >> nth=0')
        ->waitForText('Print voucher')
        ->wait(1);

    $this->shoot($page, 'invoices-row-menu', badges: ['Print voucher']);
});
