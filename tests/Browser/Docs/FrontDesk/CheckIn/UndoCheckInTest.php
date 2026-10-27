<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('undo check-in dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->singleArrivalToday()]))
        ->click('More')
        ->click('Check in')
        ->waitForText('Collect now')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Checked in')
        ->click('[aria-label="Unit actions"]')
        ->click('Undo check-in')
        ->waitForText('Nothing is posted or reversed')
        ->type('[role="dialog"]:has-text("Undo check-in") textarea', 'Checked in the wrong booking.');

    $this->shoot($page, 'undo-check-in', element: '[role="dialog"]:has-text("Undo check-in")');
});
