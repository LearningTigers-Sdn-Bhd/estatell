<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the report page with the layout button', function () {
    $page = $this->open(route('reports.guest-list', $this->property))
        ->waitForText('Arrivals (');

    $this->shoot($page, 'report-layout-button', badges: ['Layout']);
});

test('the layout sheet', function () {
    $page = $this->open(route('reports.guest-list', $this->property))
        ->waitForText('Arrivals (')
        ->click('Layout')
        ->waitForText('Reset to default');

    $this->shoot($page, 'report-layout-sheet', badges: ['Orientation', 'Columns', 'Reset to default', 'Preview PDF', 'Save'], frame: '[role="dialog"]');
});
