<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('undo check-out dialog', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S11')]))
        ->click('More')
        ->click('Check out')
        ->waitForText('Collect and check out')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Checked out')
        ->click('[aria-label="Unit actions"]')
        ->click('Undo check-out')
        ->waitForText('Folios that reopen')
        ->type('[role="dialog"]:has-text("Undo check-out") textarea', 'The guest is staying one more night.');

    $this->shoot($page, 'undo-check-out', element: '[role="dialog"]:has-text("Undo check-out")');
});
