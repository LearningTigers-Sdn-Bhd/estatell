<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('early check-out dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S8')]))
        ->click('More')
        ->click('Check out')
        ->waitForText('Nights removed');

    $this->shoot($page, 'early-departure', element: '[role="dialog"]');
});

test('early-departure policy settings', function () {
    $this->shoot($this->openPolicy('early-departure', '100'), 'early-departure-policy', highlight: ['a[href$="/settings/room-revenue"]', '[role="tab"][aria-selected="true"]']);
});
