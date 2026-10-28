<?php

beforeEach(function () {
    $this->startOnboarding();
    $this->saveOnboardingBasics();
});

test('payment methods', function () {
    $page = $this->open(route('onboarding.payment-methods.edit', $this->property->code), height: 800)
        ->waitForText('How do your guests pay?')
        ->waitForText('City ledger turns on automatically');

    $this->shoot($page, 'payment-methods');
});

test('add a payment method', function () {
    $page = $this->open(route('onboarding.payment-methods.edit', $this->property->code), height: 900)
        ->waitForText('How do your guests pay?')
        ->click('Add payment method')
        ->type('[aria-label="Name of payment method 6"]', 'DuitNow QR')
        ->click('[aria-label="Type of payment method 6"]')
        ->click('[role="option"]:has-text("E-wallet")')
        ->waitForText('Ask for ref. no.');

    $this->shoot($page, 'payment-methods-add');
});

test('every method turned off', function () {
    $page = $this->open(route('onboarding.payment-methods.edit', $this->property->code), height: 800)
        ->waitForText('How do your guests pay?')
        ->click('[aria-label="Accept Cash"]')
        ->click('[aria-label="Accept Card"]')
        ->click('[aria-label="Accept Bank transfer"]')
        ->click('[aria-label="Accept E-wallet"]')
        ->click('Save & continue')
        ->waitForText('Keep at least one payment method on.');

    $this->shoot($page, 'payment-methods-none-on');
});
