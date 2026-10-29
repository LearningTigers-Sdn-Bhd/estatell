<?php

use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * Move the clock, for the Kuala Lumpur time of 14 Oct 2026 plus the given hours (the suite starts at 20:00).
 */
$moveClockBy = function (int $hours) {
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'UTC')->addHours($hours);

    CarbonImmutable::setTestNow($now);
    Carbon::setTestNow($now);
};

test('the business date in sync', function () {
    $page = $this->open(route('dashboard', $this->property))
        ->waitForText('In sync');

    $this->shoot($page, 'business-date-in-sync', element: '[data-sidebar="footer"] [role="status"]');
});

test('the business date pending, after midnight', function () use ($moveClockBy) {
    $moveClockBy->call($this, 5);

    $page = $this->open(route('dashboard', $this->property))
        ->waitForText('Night audit pending');

    $this->shoot($page, 'business-date-pending', element: '[data-sidebar="footer"] [role="status"]');
});

test('the business date running', function () {
    BusinessDate::query()->where('property_id', $this->property->id)->current()->update(['status' => BusinessDateStatus::Auditing]);

    $page = $this->open(route('dashboard', $this->property))
        ->waitForText('Night audit running');

    $this->shoot($page, 'business-date-running', element: '[data-sidebar="footer"] [role="status"]');
});

test('the business date behind, with the dashboard alert', function () use ($moveClockBy) {
    $moveClockBy->call($this, 30);

    $page = $this->open(route('dashboard', $this->property))
        ->waitForText('days behind')
        ->waitForText('Run the night audit to catch up');

    $this->shoot($page, 'business-date-behind', badges: ['Open audit'], highlight: ['[data-sidebar="footer"] [role="status"]']);
});

test('the business date before go live', function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');

    BusinessDate::query()->where('property_id', $this->property->id)->delete();

    $page = $this->open(route('dashboard', $this->property))
        ->waitForText('Not live yet');

    $this->shoot($page, 'business-date-not-live', element: '[data-sidebar="footer"] [role="status"]');
});

test('the hotel day notice on check-out', function () use ($moveClockBy) {
    $moveClockBy->call($this, 5);

    $page = $this->open(route('reservations.show', [$this->property, $this->scenario('S9')]))
        ->click('More')
        ->wait(1)
        ->click('Check out')
        ->waitForText('Hotel day');

    $this->shoot($page, 'business-date-notice', frame: '[role="dialog"]');
});
