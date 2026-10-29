<?php

use App\Enums\BoatDirection;
use App\Enums\BoatMode;

beforeEach(function () {
    $this->signInAsScenariosOwner();
    $this->boats = $this->enableBoats();
});

/**
 * Set the trips the pages show: S7 (arrives today) on the 09:00 boat in, S3 (arrives tomorrow) on the 13:00 boat in,
 * and S11 (in house, leaves today) on an own boat out. S13 and G1's Deluxe rooms stay without a boat in, so the
 * dashboard shows their No boat rows.
 */
$setTrips = function (): void {
    $this->setBoatTrip($this->scenario('S7')->units()->firstOrFail(), BoatDirection::In, BoatMode::Scheduled, $this->boats['in0900']);
    $this->setBoatTrip($this->scenario('S3')->units()->firstOrFail(), BoatDirection::In, BoatMode::Scheduled, $this->boats['in1300']);
    $this->setBoatTrip($this->scenario('S11')->units()->firstOrFail(), BoatDirection::Out, BoatMode::Own, time: '11:30', note: "Guest's own speedboat");
};

test('boat in and boat out on the booking form', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reservations.new', $this->property), height: 1100)
        ->waitForText('Boat in');

    $this->shoot($page, 'boat-booking-form', badges: ['Boat in', 'Boat out']);
});

test('the boat in choices', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reservations.new', $this->property), height: 1100)
        ->click('#booking-boat-in')
        ->waitForText('1 / 20 seats')
        ->wait(1);

    $this->shoot($page, 'boat-booking-form-choices', frame: '[role="listbox"]');
});

test('an own boat out with a note', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reservations.new', $this->property), height: 1100)
        ->click('#booking-boat-out')
        ->click('[role="option"] >> text=Own boat')
        ->waitForText('Note')
        ->type('#booking-boat-out-note', "Guest's own speedboat")
        ->wait(1);

    $this->shoot($page, 'boat-booking-form-own-boat', badges: ['Note']);
});

test('the boat row on the reservation page', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S7')]), height: 1100)
        ->waitForText('Boat');

    $this->shoot($page, 'boat-unit-row', badges: ['Boat', 'More']);
});

test('change one boat trip', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S7')]), height: 1100)
        ->waitForText('Boat')
        ->click('button:text-is("Change") >> nth=-1')
        ->waitForText('Save boat out')
        ->wait(1);

    $this->shoot($page, 'boat-change-dialog', badges: ['Boat out', 'Save boat out'], frame: '[role="dialog"]');
});

test('set boats for every open room', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('G1')]), height: 1100)
        ->waitForText('More')
        ->click('[aria-label="More actions"]')
        ->waitForText('Set boats')
        ->wait(1);

    $this->shoot($page, 'boat-more-menu', badges: ['Set boats'], frame: '[role="menu"]');

    $page->click('[role="menuitem"]:has-text("Set boats")')
        ->waitForText('Boat in and Boat out for every open unit.')
        ->wait(1);

    $this->shoot($page, 'boat-set-boats', badges: ['Save boats'], frame: '[role="dialog"]');
});

test('no boat on the dashboard', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('dashboard', $this->property), height: 1100)
        ->click('Today')
        ->waitForText('To do today');

    $this->shoot($page, 'boat-dashboard', element: '[data-slot="card"]:has-text("To do today")');
});

test('the boat column on arrivals', function () use ($setTrips) {
    $setTrips->call($this);

    $page = $this->open(route('reports.guest-list', $this->property))
        ->waitForText('Arrivals (');

    $this->shoot($page, 'boat-arrivals-column', badges: ['Boat'], element: 'table');
});

test('the boat line on the reservation card', function () use ($setTrips) {
    $setTrips->call($this);
    $this->setBoatTrip($this->scenario('S7')->units()->firstOrFail(), BoatDirection::In, BoatMode::Own, time: '10:30');

    $page = $this->open(route('reservations.index', $this->property))
        ->type('input[placeholder^="Search reservations"]', $this->scenario('S7')->reference)
        ->waitForText('Own 10:30')
        ->wait(1);

    $this->shoot($page, 'boat-reservation-card', element: '[data-slot="card"]:has-text("'.$this->scenario('S7')->reference.'")');
});
