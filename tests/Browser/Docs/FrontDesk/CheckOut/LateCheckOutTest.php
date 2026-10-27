<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('late check-out dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S11')]))
        ->click('[aria-label="Unit actions"]')
        ->click('Late check-out')
        ->waitForText('New time')
        ->type('[role="dialog"] input[type="time"]', '16:00');

    $this->shoot($page, 'late-check-out', element: '[role="dialog"]');
});
