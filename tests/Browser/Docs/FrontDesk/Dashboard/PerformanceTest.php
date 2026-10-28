<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('performance tab', function () {
    $page = $this->open(route('dashboard', [$this->property, 'tab' => 'performance']), 1100)
        ->waitForText('Lead time')
        ->wait(2);

    $this->shoot($page, 'performance', badges: ['Last 7 days', 'Occupancy', 'ADR', 'RevPAR', 'Channel mix by source', 'Channel mix by type', 'Lead time', 'How far ahead guests book']);
});
