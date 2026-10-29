<?php

use App\Actions\CityLedger\SetCompanyPortalLogin;
use App\Models\Company;
use App\Models\User;

beforeEach(function () {
    $this->signInAsScenariosOwner();
    $this->company = Company::query()->where('property_id', $this->property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();
});

test('the portal tab with no login', function () {
    $page = $this->open(route('finance.city-ledger.companies.portal', [$this->property, $this->company]))
        ->waitForText('Portal login email')
        ->wait(1);

    $this->shoot($page, 'portal-login-empty', badges: ['Portal login email', 'Free cancellation until (days before arrival)', 'Save portal']);
});

test('saving a new email makes a login and a sign-in message', function () {
    $page = $this->open(route('finance.city-ledger.companies.portal', [$this->property, $this->company]))
        ->waitForText('Portal login email')
        ->type('portal_email', 'booking@borneo-ventures.test')
        ->type('portal_cancel_cutoff_days', '7')
        ->click('Save portal')
        ->waitForText('Sign-in message')
        ->wait(1);

    $page->wait(2);
    $page->script("const message = document.querySelector('[role=\"dialog\"] textarea'); message.value = message.value.replace(/http:\\/\\/127\\.0\\.0\\.1:\\d+/, 'https://app.estatell.com');");

    $this->shoot($page, 'portal-login-message', frame: '[role="dialog"]');

    $page->click('[role="dialog"] button:text-is("Close")')
        ->waitForText('Temp password')
        ->wait(1);

    $this->shoot($page, 'portal-login-active', badges: ['Copy message', 'Reset password']);
});

test('an existing company login gets an invitation', function () {
    User::factory()->companyUser()->create(['name' => 'Borneo Travel', 'email' => 'booking@borneo-ventures.test']);
    app(SetCompanyPortalLogin::class)(auth()->user(), $this->company, 'booking@borneo-ventures.test');

    $page = $this->open(route('finance.city-ledger.companies.portal', [$this->property, $this->company]))
        ->waitForText('Waiting to accept')
        ->wait(1);

    $this->shoot($page, 'portal-login-pending', badges: ['Waiting to accept', 'Resend']);
});

test('an email that belongs to a staff account is refused', function () {
    $page = $this->open(route('finance.city-ledger.companies.portal', [$this->property, $this->company]))
        ->waitForText('Portal login email')
        ->type('portal_email', 'owner@kinabalu-hostel.test')
        ->click('Save portal')
        ->waitForText('This email belongs to another kind of estatell account.')
        ->wait(1);

    $this->shoot($page, 'portal-login-error', badges: ['Portal login email']);
});
