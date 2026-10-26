<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('add-ons tab with add-ons', function () {
    $this->shoot($this->open(route('reservations.add-ons', [$this->property, $this->reservationByReference('PVR-26100004')])), 'add-ons-tab');
});

test('add-ons tab with nightly rows', function () {
    $this->shoot($this->open(route('reservations.add-ons', [$this->property, $this->reservationByReference('PVR-26100005')])), 'add-ons-tab-nightly');
});

test('add add-on dialog', function () {
    $page = $this->open(route('reservations.add-ons', [$this->property, $this->reservationByReference('PVR-26100004')]))
        ->click('Add add-on')
        ->click('#add-on-charge_item_id')
        ->click('Airport transfer · Per stay');

    $this->shoot($page, 'add-add-on', element: '[role="dialog"]');
});
