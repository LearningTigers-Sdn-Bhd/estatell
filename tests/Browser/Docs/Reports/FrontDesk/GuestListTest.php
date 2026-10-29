<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('guest list arrivals', function () {
    $page = $this->open(route('reports.guest-list', $this->property))
        ->waitForText('Arrivals (');

    $this->shoot($page, 'guest-list', badges: ['Arrivals (2)', 'In-house (14)', 'Departures (1)', 'Checked out (0)', 'Today', 'Layout', 'Export', 'Balance']);
});

test('guest list in house', function () {
    $page = $this->open(route('reports.guest-list', [$this->property, 'tab' => 'in-house']), height: 1000)
        ->waitForText('In-house (');

    $this->shoot($page, 'guest-list-in-house', badges: ['Today']);
});

test('guest list departures', function () {
    $page = $this->open(route('reports.guest-list', [$this->property, 'tab' => 'departures']))
        ->waitForText('Departures (');

    $this->shoot($page, 'guest-list-departures');
});

test('guest list checked out', function () {
    $page = $this->open(route('reports.guest-list', [$this->property, 'tab' => 'checked-out', 'from' => '2026-10-07', 'to' => '2026-10-14']))
        ->waitForText('Checked out (');

    $this->shoot($page, 'guest-list-checked-out', badges: ['Checked out (4)', 'Balance']);
});

test('guest list layout for one tab', function () {
    $page = $this->open(route('reports.guest-list', [$this->property, 'tab' => 'departures']))
        ->waitForText('Departures (')
        ->click('Layout')
        ->waitForText('Reset to default');

    $this->shoot($page, 'guest-list-layout', frame: '[role="dialog"]');
});
