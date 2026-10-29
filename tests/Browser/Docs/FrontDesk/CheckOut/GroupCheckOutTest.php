<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('group check-out page', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('G3')]))
        ->click('More')
        ->click('Check out all')
        ->waitForText('Departing later');

    $this->shoot($page, 'group-check-out');
});
