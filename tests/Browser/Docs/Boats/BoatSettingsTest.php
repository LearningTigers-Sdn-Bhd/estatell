<?php

use App\Enums\BoatDirection;
use App\Enums\BoatMode;

beforeEach(function () {
    $this->signInAsScenariosOwner();
    $this->boats = $this->enableBoats();
});

test('the departures tab', function () {
    $page = $this->open(route('settings.boats.departures.index', $this->property), height: 1000)
        ->waitForText('Pick-up point');

    $this->shoot($page, 'boat-departures', badges: ['Leaves', 'Pick-up point', 'Fare', 'Meals']);
});

test('add a departure', function () {
    $page = $this->open(route('settings.boats.departures.index', $this->property))
        ->waitForText('Pick-up point');

    $this->shoot($page, 'boat-departure-add', badges: ['Add departure']);

    $page->click('Add departure')
        ->waitForText('A boat that runs every day at this time.')
        ->fill('#boat-departure-leaves', '10:30')
        ->fill('#boat-departure-arrives', '11:15')
        ->wait(1);

    $this->shoot($page, 'boat-departure-sheet', badges: ['Direction', 'Leaves', 'Arrives', 'Pick-up point', 'Seats', 'Fare'], frame: '[role="dialog"]');
});

test('the meal times popover', function () {
    $page = $this->open(route('settings.boats.departures.index', $this->property))
        ->waitForText('Pick-up point')
        ->click('[aria-label="Meal times"] >> nth=0')
        ->waitForText('Edit meal periods')
        ->wait(1);

    $this->shoot($page, 'boat-meal-times', frame: '[data-slot="popover-content"]');
});

test('turn off a departure that bookings use', function () {
    $unit = $this->scenario('S7')->units()->firstOrFail();
    $this->setBoatTrip($unit, BoatDirection::In, BoatMode::Scheduled, $this->boats['in0900']);

    $page = $this->open(route('settings.boats.departures.index', $this->property))
        ->waitForText('Pick-up point')
        ->click('[aria-label="Make 09:00 boat in unavailable"]')
        ->waitForText('They keep it. New bookings cannot choose it.')
        ->wait(1);

    $this->shoot($page, 'boat-departure-turn-off', badges: ['Cancel', 'Turn off'], frame: '[role="alertdialog"]');
});

test('the fares tab', function () {
    $page = $this->open(route('settings.boats.fares.index', $this->property))
        ->waitForText('Used by');

    $this->shoot($page, 'boat-fares', badges: ['Name', 'Children', 'Taxes', 'Used by', 'Add fare']);
});

test('add a fare', function () {
    $page = $this->open(route('settings.boats.fares.index', $this->property))
        ->waitForText('Used by')
        ->click('Add fare')
        ->waitForText('The one-way price per guest for a boat.')
        ->fill('#boat-fare-name', 'Night ferry')
        ->wait(1);

    $this->shoot($page, 'boat-fare-sheet', badges: ['Details', 'Price · one way, per guest', 'Children', 'Taxes'], frame: '[role="dialog"]');
});
