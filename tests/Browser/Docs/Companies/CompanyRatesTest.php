<?php

use App\Actions\CityLedger\CreateCompany;
use App\Actions\Rates\AddRatePlanCompanies;
use App\Actions\Rates\SetRatePlanCompanyAccess;
use App\Actions\Rates\UpdateRatePlanCompany;
use App\Enums\CompanyAccess;
use App\Enums\CompanyMarket;
use App\Enums\CompanyRateMarket;
use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $owner = auth()->user();
    $create = app(CreateCompany::class);

    $this->borneo = Company::query()->where('property_id', $this->property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();
    $this->borneo->forceFill(['market' => CompanyMarket::Local])->save();

    $this->marine = $create($owner, $this->property, [
        'name' => 'Sabah Marine Tours',
        'type' => CompanyType::TravelAgent,
        'market' => CompanyMarket::International,
        'direct_bill_enabled' => true,
    ]);
    $create($owner, $this->property, [
        'name' => 'Petronas Carigali Miri',
        'type' => CompanyType::Corporate,
        'market' => CompanyMarket::Local,
        'direct_bill_enabled' => true,
    ]);

    $this->corporate = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Corporate')->firstOrFail();
    $this->roomOnly = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Room Only')->firstOrFail();

    app(AddRatePlanCompanies::class)($owner, $this->corporate, [$this->marine->id]);
    app(UpdateRatePlanCompany::class)($owner, $this->corporate, $this->marine, true, true);
});

test('the company rates tab', function () {
    $page = $this->open(route('finance.city-ledger.companies.rates', [$this->property, $this->borneo]))
        ->waitForText('Corporate')
        ->wait(1);

    $this->shoot($page, 'company-rates-company-tab', badges: ['Rate plan', 'Front desk']);
});

test('the company rates tab with no rates', function () {
    $company = Company::query()->where('name', 'Petronas Carigali Miri')->firstOrFail();

    $page = $this->open(route('finance.city-ledger.companies.rates', [$this->property, $company]))
        ->waitForText('No company rates yet.')
        ->wait(1);

    $this->shoot($page, 'company-rates-company-empty');
});

test('only these companies', function () {
    $page = $this->open(route('settings.rate-plans.companies.edit', [$this->property, $this->corporate]), 1000)
        ->waitForText('Who can use this rate')
        ->wait(2);

    $this->shoot($page, 'company-rates-only-these', badges: ['Who can use this rate', 'Market', 'Add companies', 'Login', 'Front desk', 'Portal']);
});

test('add companies to a rate', function () {
    $page = $this->open(route('settings.rate-plans.companies.edit', [$this->property, $this->corporate]), 1000)
        ->waitForText('Who can use this rate')
        ->wait(2);

    $this->shoot($page, 'company-rates-add-closed', badges: ['Add companies']);

    $page->click('Add companies')
        ->waitForText('Both switches start on.')
        ->wait(1);

    $this->shoot($page, 'company-rates-add-dialog', badges: ['Search'], frame: '[role="dialog"]');
});

test('all companies', function () {
    app(SetRatePlanCompanyAccess::class)(auth()->user(), $this->roomOnly, CompanyAccess::All, true, false, CompanyRateMarket::Local);

    $page = $this->open(route('settings.rate-plans.companies.edit', [$this->property, $this->roomOnly]), 1100)
        ->waitForText('Where')
        ->wait(1);

    $this->shoot($page, 'company-rates-all-companies', badges: ['Where', 'Market', 'Save']);
});

test('everyone', function () {
    $page = $this->open(route('settings.rate-plans.companies.edit', [$this->property, $this->roomOnly]), 800)
        ->waitForText('Who can use this rate')
        ->wait(1);

    $this->shoot($page, 'company-rates-everyone', badges: ['Everyone', 'All companies', 'Only these companies']);
});
