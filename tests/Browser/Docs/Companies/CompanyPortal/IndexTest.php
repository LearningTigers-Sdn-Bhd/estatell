<?php

use App\Models\ArInvoice;
use App\Models\Company;

beforeEach(function () {
    $this->company = $this->signInAsCompanyPortalUser();
});

/**
 * Give the company a cancellation cut-off, a credit limit and one open invoice, so the portal pages have figures.
 */
function portalIndexAddFigures(Company $company): void
{
    $company->update(['portal_cancel_cutoff_days' => 7, 'credit_limit' => '20000.00']);

    ArInvoice::factory()->create([
        'property_id' => $company->property_id,
        'company_id' => $company->id,
        'created_by' => $company->property->users()->firstOrFail()->id,
        'number' => 'AR-0091',
        'issued_on' => '2026-10-02',
        'due_on' => '2026-10-12',
        'amount' => '3000.00',
    ]);
}

test('the property dashboard', function () {
    portalIndexAddFigures($this->company);

    $page = $this->open(route('portal.show', $this->property->code), height: 1000)
        ->waitForText('Upcoming arrivals')
        ->wait(1);

    $this->shoot($page, 'index-dashboard', badges: ['Book a stay', 'Open balance', 'Credit available', 'Upcoming arrivals', 'Open invoices', 'Recent bookings'], badgesFromLeft: 0);
});

test('the properties list', function () {
    $page = $this->open(route('portal.properties'))
        ->waitForText('Kinabalu Hostel & Suites')
        ->wait(1);

    $this->shoot($page, 'index-properties', badgesFromLeft: 0);
});

test('the company details page', function () {
    $page = $this->open(route('portal.company', $this->property->code), height: 1100)
        ->waitForText('Only we manage our tax and billing details')
        ->wait(1);

    $this->shoot($page, 'index-company-details', badges: ['Only we manage our tax and billing details'], badgesFromLeft: 0);
});

test('the statements page', function () {
    portalIndexAddFigures($this->company);

    $page = $this->open(route('portal.statements', $this->property->code), height: 800)
        ->waitForText('AR-0091')
        ->wait(1);

    $this->shoot($page, 'index-statements', badgesFromLeft: 0);
});
