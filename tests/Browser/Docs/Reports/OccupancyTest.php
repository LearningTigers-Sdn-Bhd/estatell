<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('occupancy report', function () {
    $page = $this->open(route('reports.occupancy', ['property' => $this->property, 'from' => '2026-10-08', 'to' => '2026-10-14']))
        ->wait(1);

    $this->shoot($page, 'occupancy',
        badges: ['Custom dates', 'Occupancy %', 'ADR', 'RevPAR', 'Day use sold'],
    );
});
