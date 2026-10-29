<?php

use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\SuspendCompany;
use App\Actions\CityLedger\UpdatePortalCompanyDetails;
use App\Enums\CompanyMarket;
use App\Enums\CompanyType;
use App\Models\Company;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $create = app(CreateCompany::class);
    $owner = auth()->user();

    $create($owner, $this->property, [
        'name' => 'Sabah Marine Tours',
        'type' => CompanyType::TravelAgent,
        'market' => CompanyMarket::International,
        'contact_email' => 'bookings@sabahmarine.test',
        'credit_limit' => '20000.00',
        'payment_terms_days' => 30,
        'direct_bill_enabled' => true,
    ]);
    $create($owner, $this->property, [
        'name' => 'Jabatan Kerja Raya Sabah',
        'type' => CompanyType::Government,
        'market' => CompanyMarket::Local,
        'contact_email' => 'akaun@jkr-sabah.test',
        'credit_limit' => null,
        'payment_terms_days' => 60,
        'direct_bill_enabled' => true,
    ]);

    $this->borneo = Company::query()->where('property_id', $this->property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();
});

test('the companies list', function () {
    $page = $this->open(route('finance.city-ledger.companies.index', $this->property))
        ->waitForText('Sabah Marine Tours')
        ->wait(1);

    $this->shoot($page, 'companies-list', badges: ['Add company']);
});

test('the add company menu', function () {
    $page = $this->open(route('finance.city-ledger.companies.index', $this->property))
        ->waitForText('Sabah Marine Tours')
        ->click('Add company')
        ->waitForText('Bulk add')
        ->wait(1);

    $this->shoot($page, 'companies-add-menu', badges: ['Add one', 'Bulk add']);
});

test('the add company sheet', function () {
    $page = $this->open(route('finance.city-ledger.companies.index', $this->property), 1100)
        ->waitForText('Sabah Marine Tours')
        ->wait(1);

    $this->shoot($page, 'companies-add-closed', badges: ['Add company']);

    $page->click('Add company')
        ->click('[role="menuitem"]:has-text("Add one")')
        ->waitForText('Only the name and type are required.')
        ->type('#add-company-name', 'Petronas Carigali Miri')
        ->wait(1);

    $this->shoot($page, 'companies-add-sheet', badges: ['Company', 'Credit', 'Portal'], frame: '[role="dialog"]');

    $page->script("document.querySelectorAll('[role=\"dialog\"] *').forEach((el) => { if (el.scrollHeight > el.clientHeight + 20) { el.scrollTop = el.scrollHeight; } });");
    $page->wait(1);

    $this->shoot($page, 'companies-add-sheet-portal', badges: ['Portal login email', 'Free cancellation until (days before arrival)'], frame: '[role="dialog"]');
});

test('the details tab', function () {
    $page = $this->open(route('finance.city-ledger.companies.show', [$this->property, $this->borneo]), 1300)
        ->waitForText('Tax numbers and billing address')
        ->wait(1);

    $this->shoot($page, 'companies-details', badges: ['Details', 'Credit', 'Rates', 'Portal', 'Payments', 'Statement', 'More actions']);
});

test('the tax numbers when the company manages them', function () {
    $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Sabah Marine Tours')->firstOrFail();

    app(UpdatePortalCompanyDetails::class)(auth()->user(), $company, [
        'profile_managed_by_company' => true,
        'brn' => '202001012345',
        'tin' => 'C12345678901',
        'billing_address_line1' => 'Lot 5, Jalan Pantai',
        'billing_city' => 'Kota Kinabalu',
        'billing_postal_code' => '88000',
        'billing_state_code' => 'Sabah',
        'billing_country_code' => 'MY',
    ]);

    $page = $this->open(route('finance.city-ledger.companies.show', [$this->property, $company]), 1300)
        ->waitForText('The company manages these details in the company portal.')
        ->wait(1);

    $this->shoot($page, 'companies-managed', badges: ['Tax numbers and billing address']);
});

test('the credit tab', function () {
    $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Sabah Marine Tours')->firstOrFail();

    $page = $this->open(route('finance.city-ledger.companies.credit', [$this->property, $company]), 1000)
        ->waitForText('How does this company pay?')
        ->wait(1);

    $this->shoot($page, 'companies-credit', badges: ['Pay at check-out', 'Direct billing', 'Credit limit (MYR)', 'Payment terms (days)']);
});

test('the pay at check-out credit tab', function () {
    $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Sabah Marine Tours')->firstOrFail();

    $page = $this->open(route('finance.city-ledger.companies.credit', [$this->property, $company]), 900)
        ->waitForText('How does this company pay?')
        ->click('Pay at check-out')
        ->wait(1);

    $this->shoot($page, 'companies-credit-checkout', badges: ['Pay at check-out']);
});

test('suspend a company', function () {
    $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Jabatan Kerja Raya Sabah')->firstOrFail();

    $page = $this->open(route('finance.city-ledger.companies.show', [$this->property, $company]), 1300)
        ->waitForText('More actions')
        ->click('More actions')
        ->waitForText('Suspend')
        ->wait(1);

    $this->shoot($page, 'companies-more-actions', badges: ['Add from old system', 'Suspend']);

    $page->click('[role="menuitem"]:has-text("Suspend")')
        ->waitForText('Suspend company')
        ->wait(1);

    $this->shoot($page, 'companies-suspend', badges: ['Suspend company'], frame: '[role="alertdialog"]');
});

test('a suspended company', function () {
    $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Jabatan Kerja Raya Sabah')->firstOrFail();
    app(SuspendCompany::class)(auth()->user(), $company);

    $page = $this->open(route('finance.city-ledger.companies.index', $this->property))
        ->waitForText('Sabah Marine Tours')
        ->click('[aria-label="Status"]')
        ->click('[role="option"]:has-text("All statuses")')
        ->wait(1);

    $this->shoot($page, 'companies-list-suspended');
});
