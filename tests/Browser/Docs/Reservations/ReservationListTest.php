<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('reservation list', function () {
    $this->shoot($this->open(route('reservations.index', $this->property)), 'reservation-list');
});
