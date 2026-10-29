<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('staff list', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->waitForText('People who can sign in to this property.');

    $this->shoot($page, 'staff-overview', badges: ['Add staff']);
});
