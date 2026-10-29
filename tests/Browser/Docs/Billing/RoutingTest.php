<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\CreateRoutingRule;
use App\Enums\TransactionCategory;
use App\Models\Company;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('folio menu with the routing items', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G2')]))
        ->click('More actions')
        ->wait(1);

    $this->shoot($page, 'routing-menu', badges: ['Routing rules', 'Third-party folio']);
});

test('routing rules sheet', function () {
    $reservation = $this->scenario('G2');
    $company = Company::query()->where('property_id', $this->property->id)->where('direct_bill_enabled', true)->firstOrFail();
    $agent = app(CreateFolio::class)->thirdParty($reservation, null, $company->id, auth()->user());
    $unit = $reservation->units()->firstOrFail();
    app(CreateRoutingRule::class)->manual(auth()->user(), $reservation, $agent, $unit->id, TransactionCategory::Room);

    $page = $this->open(route('reservations.folio', [$this->property, $reservation]), height: 1100)
        ->click('More actions')
        ->click('Routing rules')
        ->waitForText('Add rule')
        ->wait(1);

    $this->shoot($page, 'routing-sheet', badges: ['Add rule'], frame: '[role="dialog"]');
});

test('new third-party folio dialog', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G1')]))
        ->click('More actions')
        ->click('Third-party folio')
        ->waitForText('New third-party folio');

    $this->shoot($page, 'routing-third-party-folio', frame: '[role="dialog"]');
});

test('bill to guest on the guests tab', function () {
    $page = $this->open(route('reservations.guests', [$this->property, $this->scenario('G1')]))
        ->click('[aria-label^="Actions for"] >> nth=1')
        ->wait(1);

    $this->shoot($page, 'routing-bill-to-guest', badges: ['Bill to this guest']);
});
