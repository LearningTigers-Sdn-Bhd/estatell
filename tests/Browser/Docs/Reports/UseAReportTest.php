<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('a report page', function () {
    $page = $this->open(route('reports.payments', [$this->property, 'from' => '2026-10-01', 'to' => '2026-10-14']), height: 1000)
        ->waitForText('Payments');

    $this->shoot($page, 'report-frame', badges: ['Custom dates', 'By method', 'Refunds only', 'Layout', 'Export', 'Amount'], highlight: ['tfoot']);
});

test('the quick dates list', function () {
    $page = $this->open(route('reports.payments', $this->property))
        ->waitForText('Payments')
        ->click('[aria-label="Quick dates"]')
        ->wait(1);

    $this->shoot($page, 'report-quick-dates');
});

test('selected rows and the bulk bar', function () {
    $page = $this->open(route('reports.payments', [$this->property, 'from' => '2026-10-01', 'to' => '2026-10-14']), height: 1000)
        ->waitForText('Payments')
        ->click('[aria-label="Select row"] >> nth=0')
        ->click('[aria-label="Select row"] >> nth=1')
        ->waitForText('2 selected');

    $this->shoot($page, 'report-selected-rows', badges: ['2 selected'], highlight: ['[role="toolbar"]']);
});

test('the export menu', function () {
    $page = $this->open(route('reports.payments', [$this->property, 'from' => '2026-10-01', 'to' => '2026-10-14']))
        ->waitForText('Payments')
        ->click('Export')
        ->waitForText('Excel');

    $this->shoot($page, 'report-export-menu');
});
