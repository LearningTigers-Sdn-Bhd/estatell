<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('operations tab', function () {
    $page = $this->open(route('settings.operations', $this->property->code), height: 1100)
        ->waitForText('Run night audit automatically');

    $this->shoot($page, 'operations', badges: [
        'Night audit time',
        'Missed arrival',
        'Overdue departure',
        'Weekend nights',
        'Migration window (days)',
        'Run night audit automatically',
    ]);
});
