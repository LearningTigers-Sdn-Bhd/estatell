<?php

beforeEach(function () {
    $this->startOnboarding();
    $this->saveOnboardingBasics();
});

test('companies', function () {
    $page = $this->open(route('onboarding.companies.edit', $this->property->code), height: 800)
        ->waitForText('Do companies or travel agents book with you');

    $this->shoot($page, 'companies');
});

test('company with details', function () {
    $page = $this->open(route('onboarding.companies.edit', $this->property->code), height: 1000)
        ->waitForText('Do companies or travel agents book with you')
        ->click('Add company')
        ->type('[aria-label="Name of company 1"]', 'Petronas Carigali')
        ->type('[aria-label="Contact email of company 1"]', 'travel@petronas.my')
        ->type('[aria-label="Credit limit of company 1"]', '20000')
        ->type('[aria-label="Payment terms of company 1"]', '30')
        ->click('[aria-label="More options for Petronas Carigali"]')
        ->type('[aria-label="Portal login email of company 1"]', 'booking@petronas.my')
        ->type('[aria-label="Cancellation days of company 1"]', '7')
        ->waitForText('Ready after go-live');

    $this->shoot($page, 'companies-details');
});
