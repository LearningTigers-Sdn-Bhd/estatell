<?php

use App\Models\ArInvoice;
use App\Models\Company;
use App\Models\Property;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Give Borneo Ventures a credit limit and an open invoice, so its open folios (S7 and G2) and the invoice together
 * take it over the limit.
 */
function creditControlGoOverLimit(Property $property): Company
{
    $company = Company::query()->where('property_id', $property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();
    $company->update(['credit_limit' => '5000.00']);

    ArInvoice::factory()->create([
        'property_id' => $property->id,
        'company_id' => $company->id,
        'created_by' => auth()->id(),
        'number' => 'INV-5001',
        'issued_on' => '2026-10-01',
        'due_on' => '2026-10-31',
        'amount' => '6000.00',
    ]);

    return $company;
}

test('the credit tab with a limit', function () {
    $company = creditControlGoOverLimit($this->property);

    $page = $this->open(route('finance.city-ledger.companies.credit', [$this->property, $company]), height: 1000)
        ->waitForText('Exposure')
        ->wait(1);

    $this->shoot($page, 'credit-control-credit-tab', badges: ['Open invoices', 'Open folios', 'Exposure', 'Headroom', 'Credit limit (MYR)']);
});

test('the credit tab with no limit', function () {
    $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();

    $page = $this->open(route('finance.city-ledger.companies.credit', [$this->property, $company]), height: 1000)
        ->waitForText('No limit')
        ->wait(1);

    $this->shoot($page, 'credit-control-no-limit', badges: ['Headroom']);
});

test('the over limit badge on the companies list', function () {
    creditControlGoOverLimit($this->property);

    $page = $this->open(route('finance.city-ledger.companies.index', $this->property))
        ->waitForText('Over limit')
        ->wait(1);

    $this->shoot($page, 'credit-control-over-limit-list', badges: ['Over limit']);
});

test('the credit limit override at check-in', function () {
    creditControlGoOverLimit($this->property);

    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S7')]))
        ->click('More')
        ->wait(1);

    $this->shoot($page, 'credit-control-check-in-menu', badges: ['Check in']);

    $page->click('Check in')
        ->waitForText('Credit limit override reason')
        ->wait(1);

    $this->shoot($page, 'credit-control-override', badges: ['Credit limit override reason'], frame: '[role="dialog"]');
});
