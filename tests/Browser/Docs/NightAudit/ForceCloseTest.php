<?php

use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->signInAsScenariosOwner();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

    $unit = $this->scenario('B1')->units()->where('status', UnitStatus::CheckedIn->value)->firstOrFail();
    $unit->nights()->update(['bed_id' => null, 'room_id' => null]);

    // The arrivals that are not here yet are late arrivals, so the sheet stays short.
    ReservationUnit::query()->where('property_id', $this->property->id)->where('status', UnitStatus::Confirmed->value)->update(['late_arrival_expected' => true]);
});

test('force close link', function () {
    $page = $this->open(route('dashboard', $this->property), 900)
        ->waitForText('Run Night Audit')
        ->click('Run Night Audit')
        ->waitForText('Fix before running')
        ->wait(1);

    $this->shoot($page, 'force-close-link', badges: ['Force close…'], frame: '[role="dialog"]');
});

test('force close reason', function () {
    $page = $this->open(route('dashboard', $this->property), 900)
        ->waitForText('Run Night Audit')
        ->click('Run Night Audit')
        ->waitForText('Fix before running')
        ->click('Force close…')
        ->waitForText('Reason')
        ->type('#night-audit-reason', 'The guest in the dorm moves beds tomorrow morning. We will fix it then.')
        ->wait(1);

    $this->shoot($page, 'force-close-reason', badges: ['Reason', 'Force close'], frame: '[role="dialog"]');
});
