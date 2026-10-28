<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('payment methods list', function () {
    $page = $this->open(route('settings.payment-methods.index', $this->property))
        ->waitForText('New payment method');

    $this->shoot($page, 'payment-methods', badges: ['Available', 'Payment method', 'Type', 'Transaction code', 'Reference', 'Surcharge', 'New payment method']);
});

test('new payment method sheet', function () {
    $page = $this->open(route('settings.payment-methods.index', $this->property), height: 900)
        ->click('New payment method')
        ->waitForText('Configure its availability, references, and optional surcharge.')
        ->type('#payment-method-name', 'DuitNow QR')
        ->type('#payment-method-code', 'DUITNOW');

    $this->shoot($page, 'new-payment-method', element: '[role="dialog"]');
});
