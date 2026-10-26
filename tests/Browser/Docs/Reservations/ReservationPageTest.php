<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('reservation page units tab labelled', function () {
    $page = $this->open(route('reservations.show', [$this->property, $this->largestGroup()]))
        ->waitForText('Change price');

    $this->shoot($page, 'reservation-page-labelled', badges: [
        'Semporna Divers Club',
        'Confirmed',
        'Add unit',
        'More',
        'Units',
        'Sea View Room',
        'Change price',
        'Night',
        'Booker',
        'Still to pay',
    ]);
});

test('reservation page guests tab', function () {
    $this->shoot($this->open(route('reservations.guests', [$this->property, $this->largestGroup()])), 'reservation-page-guests');
});

test('reservation page guests tab grouped', function () {
    $page = $this->open(route('reservations.guests', [$this->property, $this->largestGroup()]))
        ->click('[aria-label="Grouped by unit"]')
        ->waitForText('in this unit');

    $this->shoot($page, 'reservation-page-guests-grouped');
});

test('reservation page guest details open', function () {
    $page = $this->open(route('reservations.guests', [$this->property, $this->largestGroup()]))
        ->click('[aria-label="Show the details of Rachel Lim"]')
        ->waitForText('Scan Card');
    $page->script("[...document.querySelectorAll('button')].find((b) => b.textContent.includes('Scan Card'))?.closest('div.rounded-lg, div.border')?.remove()");

    $this->shoot($page, 'reservation-page-guest-details');
});

test('reservation page folio tab', function () {
    $this->shoot($this->open(route('reservations.folio', [$this->property, $this->reservationByReference('PVR-26100017')])), 'reservation-page-folio');
});

test('reservation page folio tab scheduled charges', function () {
    $this->shoot($this->open(route('reservations.folio', [$this->property, $this->largestGroup()])), 'reservation-page-folio-scheduled');
});

test('reservation page activity tab', function () {
    $this->shoot($this->open(route('reservations.activity', [$this->property, $this->largestGroup()])), 'reservation-page-activity');
});

test('reservation page summary source', function () {
    $page = $this->open(route('reservations.activity', [$this->property, $this->largestGroup()]), height: 1100);

    $this->shoot($page, 'reservation-page-summary-source', checkErrors: false);
});
