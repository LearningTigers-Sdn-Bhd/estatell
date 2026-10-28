<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('folio menu with move charges', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G1')]))
        ->click('More actions')
        ->wait(1);

    $this->shoot($page, 'move-charges-menu', badges: ['Move charges']);
});

test('move charges page', function () {
    $page = $this->open(route('reservations.folio.move', [$this->property, $this->scenario('G1')]), height: 1100)
        ->waitForText('Quick select')
        ->click('All charges')
        ->click('label[for$="-all"] >> nth=0')
        ->wait(1);

    $this->shoot($page, 'move-charges', badges: ['Quick select', 'Charge everything to Master', 'Incoming']);
});
