<?php

use App\Actions\CityLedger\CreateCompany;
use App\Actions\Payments\RecordPayment;
use App\Actions\Rates\AddRatePlanCompanies;
use App\Data\Payments\NewPaymentData;
use App\Enums\CompanyMarket;
use App\Enums\CompanyType;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\RatePlan;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    $create = app(CreateCompany::class);

    $this->petronas = $create(auth()->user(), $this->property, [
        'name' => 'Petronas Carigali Miri',
        'type' => CompanyType::Corporate,
        'market' => CompanyMarket::Local,
        'credit_limit' => '50000.00',
        'payment_terms_days' => 45,
        'direct_bill_enabled' => true,
    ]);
    $create(auth()->user(), $this->property, [
        'name' => 'Sabah Marine Tours',
        'type' => CompanyType::TravelAgent,
        'market' => CompanyMarket::International,
        'direct_bill_enabled' => true,
    ]);
    $create(auth()->user(), $this->property, [
        'name' => 'Tiny Credit Trading',
        'type' => CompanyType::Corporate,
        'credit_limit' => '50.00',
        'payment_terms_days' => 30,
        'direct_bill_enabled' => true,
    ]);

    $this->openDialog = function (string $code): AwaitableWebpage {
        return $this->open(route('reservations.show', [$this->property, $this->scenario($code)]))
            ->waitForText('More')
            ->click('More')
            ->click('[role="menuitem"]:has-text("Change company")')
            ->waitForText('Save company');
    };

    $this->pick = function (AwaitableWebpage $page, string $name): AwaitableWebpage {
        return $page->click('#change-company_id')
            ->type('#change-company_id', $name)
            ->click('[role="option"]:has-text("'.$name.'")')
            ->wait(1);
    };
});

test('the more menu', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S11')]))
        ->waitForText('More')
        ->click('More')
        ->waitForText('Change company')
        ->wait(1);

    $this->shoot($page, 'change-company-menu', badges: ['Change company']);
});

test('the change company dialog', function () {
    $page = ($this->openDialog)('S11');

    $this->shoot($page, 'change-company-dialog', badges: ['Company', 'Save company'], frame: '[role="dialog"]');

    ($this->pick)($page, 'Petronas Carigali Miri');

    $this->shoot($page, 'change-company-picked', badges: ['Save company'], frame: '[role="dialog"]');
});

test('a plan only the old company can book', function () {
    $page = ($this->openDialog)('S7');
    ($this->pick)($page, 'Sabah Marine Tours')
        ->click('button:text-is("Save company")')
        ->waitForText('Units use plans only the old company can book.')
        ->wait(1);

    $this->shoot($page, 'change-company-plan-conflict', frame: '[role="dialog"]');
});

test('the master folio holds money', function () {
    $reservation = $this->scenario('S7');
    $corporate = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Corporate')->firstOrFail();
    app(AddRatePlanCompanies::class)(auth()->user(), $corporate, [$this->petronas->id]);

    $master = Folio::query()->where('reservation_id', $reservation->id)->where('type', FolioType::Master)->firstOrFail();
    $cash = PaymentMethod::query()->where('property_id', $this->property->id)->where('type', PaymentMethodType::Cash)->where('active', true)->firstOrFail();
    app(RecordPayment::class)(auth()->user(), $reservation, $master, null, new NewPaymentData($cash->id, PaymentPurpose::Advance, '200.00', null, (string) Str::uuid()));

    $page = ($this->openDialog)('S7');
    ($this->pick)($page, 'Petronas Carigali Miri')
        ->click('button:text-is("Save company")')
        ->waitForText('on the Master folio first')
        ->wait(1);

    $this->shoot($page, 'change-company-master-money', frame: '[role="dialog"]');
});

test('the credit limit', function () {
    $page = ($this->openDialog)('S11');
    ($this->pick)($page, 'Tiny Credit Trading')
        ->click('button:text-is("Save company")')
        ->waitForText('Reason for going over the limit')
        ->wait(1);

    $this->shoot($page, 'change-company-credit', badges: ['Reason for going over the limit'], frame: '[role="dialog"]');
});
