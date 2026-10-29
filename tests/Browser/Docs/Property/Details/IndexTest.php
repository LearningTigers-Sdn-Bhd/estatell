<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('details tabs', function () {
    $page = $this->open(route('settings.profile', $this->property->code))
        ->waitForText('Save profile');

    $this->shoot($page, 'details', badges: [
        'Profile',
        'Business licence',
        'General',
        'Operations',
        'Age groups',
        'Meal periods',
        'Support access',
    ]);
});
