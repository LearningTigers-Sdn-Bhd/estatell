<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('meal periods tab', function () {
    $page = $this->open(route('settings.meal-periods', $this->property->code))
        ->waitForText('Save meal periods');

    $this->shoot($page, 'meal-periods', badges: ['Served', 'Starts at', 'Ends at', 'Add meal period']);
});

test('meal counts report', function () {
    $page = $this->open(route('reports.meal-counts', ['property' => $this->property->code, 'from' => '2026-10-14', 'to' => '2026-10-16']))
        ->waitForText('Meal counts');

    $this->shoot($page, 'meal-counts');
});
