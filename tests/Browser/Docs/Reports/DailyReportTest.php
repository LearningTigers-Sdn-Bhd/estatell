<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('daily report overview', function () {
    $page = $this->open(route('reports.daily-report', ['property' => $this->property, 'tab' => 'overview', 'from' => '2026-10-08', 'to' => '2026-10-14']))
        ->wait(1);

    $this->shoot($page, 'daily-report-overview',
        badges: ['Custom dates', 'Net revenue', 'Net at desk'],
    );
});

test('daily report revenue tab', function () {
    $page = $this->open(route('reports.daily-report', ['property' => $this->property, 'tab' => 'revenue', 'from' => '2026-10-08', 'to' => '2026-10-14']))
        ->wait(1);

    $this->shoot($page, 'daily-report-revenue',
        badges: ['Day', 'Accommodation', 'Service Tax (SST)'],
    );
});

test('daily report charges tab', function () {
    $page = $this->open(route('reports.daily-report', ['property' => $this->property, 'tab' => 'charges', 'from' => '2026-10-13', 'to' => '2026-10-13']))
        ->wait(1);

    $this->shoot($page, 'daily-report-charges',
        badges: ['All buckets', 'Guest / payer', 'Base'],
    );
});

test('daily report cashier activity tab', function () {
    $page = $this->open(route('reports.daily-report', ['property' => $this->property, 'tab' => 'cashier', 'from' => '2026-10-08', 'to' => '2026-10-14']))
        ->wait(1);

    $this->shoot($page, 'daily-report-cashier',
        badges: ['By staff', 'Refunds only', 'Staff'],
    );
});
