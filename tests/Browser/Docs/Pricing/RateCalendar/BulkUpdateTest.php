<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('bulk edit', function () {
    $page = $this->open(route('rates.calendar.bulk', $this->property->code), height: 1500)
        ->waitForText('Restrictions');

    $this->shoot($page, 'bulk-edit', badges: ['Rate plans', 'Dates', 'Weekday / weekend', 'Apply to all rows', 'Restrictions']);
});
