<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('general tab', function () {
    $page = $this->open(route('settings.edit', $this->property->code), height: 1100)
        ->waitForText('Assign rooms automatically');

    $this->shoot($page, 'general', badges: [
        'Currency',
        'Time zone',
        'Default language',
        'Arrival grace period (minutes)',
        'Check-in time',
        'Check-out time',
        'Assign rooms automatically',
    ]);
});
