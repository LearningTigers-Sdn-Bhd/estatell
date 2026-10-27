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
