<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('overdue departure on the dashboard', function () {
    $page = $this->open(route('dashboard', $this->property))
        ->click('Today')
        ->waitForText('Ong Kok Leong');

    $this->shoot($page, 'overdue-dashboard', badges: ['Overdue']);
});
