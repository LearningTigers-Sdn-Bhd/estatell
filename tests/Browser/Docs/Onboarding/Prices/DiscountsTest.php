<?php

beforeEach(function () {
    $this->startOnboarding();
    $this->saveOnboardingBasics();
});

test('discounts', function () {
    $page = $this->open(route('onboarding.discounts.edit', $this->property->code), height: 800)
        ->waitForText('Discounts staff can apply')
        ->click('Add discount')
        ->type('[aria-label="Name of discount 2"]', 'Walk-in')
        ->click('[aria-label="Type of discount 2"]')
        ->click('[role="option"]:has-text("Percent")')
        ->type('[aria-label="Percentage of discount 2"]', '10')
        ->click('[aria-label="Applies to of discount 2"]')
        ->click('[role="option"]:has-text("Room only")')
        ->wait(1);

    $this->shoot($page, 'discounts');
});

test('fixed discount details', function () {
    $page = $this->open(route('onboarding.discounts.edit', $this->property->code), height: 800)
        ->waitForText('Discounts staff can apply')
        ->click('Add discount')
        ->type('[aria-label="Name of discount 2"]', 'Returning guest')
        ->click('[aria-label="Type of discount 2"]')
        ->click('[role="option"]:has-text("Fixed")')
        ->type('tr:has([aria-label="Name of discount 2"]) input[placeholder="e.g. 20.00"]', '20')
        ->click('[aria-label="More options for Returning guest"]')
        ->waitForText('Staff can change')
        ->wait(1);

    $this->shoot($page, 'discounts-details');
});
