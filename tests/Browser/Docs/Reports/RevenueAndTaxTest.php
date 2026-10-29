<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('revenue report', function () {
    $page = $this->open(route('reports.revenue', ['property' => $this->property, 'from' => '2026-10-12', 'to' => '2026-10-13']), height: 1100)
        ->wait(1);

    $this->shoot($page, 'revenue',
        badges: ['Day', 'Code', 'Amount'],
    );
});

test('tax report', function () {
    $page = $this->open(route('reports.tax', ['property' => $this->property, 'from' => '2026-10-08', 'to' => '2026-10-14']))
        ->wait(1);

    $this->shoot($page, 'tax',
        badges: ['Day', 'Base', 'Tax amount'],
    );
});
