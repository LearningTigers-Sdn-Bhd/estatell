<?php

use App\Actions\Folio\ReopenFolio;
use App\Actions\FrontDesk\CancelUnit;
use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioType;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\PaymentMethod;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

/**
 * The business date, 14 Oct, has ended: the clock is on the next morning in Kuala Lumpur.
 */
$endTheDay = fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));

/**
 * Take the room and bed off a guest who is in house, so tonight's night has none.
 */
$takeRoomAway = function () {
    $unit = $this->scenario('B1')->units()->where('status', UnitStatus::CheckedIn->value)->firstOrFail();
    $unit->nights()->update(['bed_id' => null, 'room_id' => null]);
};

/**
 * Add the three folio warnings: a guest folio still open after check-out (S1), money held on a cancelled booking (G5)
 * and a master folio nobody billed (G7). The arrivals that are not here yet are marked as late arrivals, so the
 * sheet stays short.
 */
$addFolioWarnings = function () {
    $user = auth()->user();

    app(ReopenFolio::class)($user, Folio::query()->where('reservation_unit_id', $this->scenario('S1')->units()->firstOrFail()->id)->where('type', FolioType::Unit->value)->firstOrFail());

    $cancelled = $this->scenario('G5');
    $cash = PaymentMethod::query()->where('property_id', $this->property->id)->where('type', PaymentMethodType::Cash)->where('active', true)->firstOrFail();
    $payment = app(RecordPayment::class)($user, $cancelled, null, $cancelled->units()->firstOrFail(), new NewPaymentData($cash->id, PaymentPurpose::Advance, '150.00', null, (string) Str::uuid()));
    expect($payment->isRefused())->toBeFalse();

    foreach ($this->scenario('G7')->units()->where('status', UnitStatus::Confirmed->value)->get() as $unit) {
        expect(app(CancelUnit::class)($user, $unit, doNotCharge: true, reason: 'Cancelled for the docs')->isRefused())->toBeFalse();
    }

    ReservationUnit::query()->where('property_id', $this->property->id)->where('status', UnitStatus::Confirmed->value)->update(['late_arrival_expected' => true]);
};

test('run night audit button', function () {
    $page = $this->open(route('dashboard', $this->property))
        ->waitForText('Run Night Audit');

    $this->shoot($page, 'readiness-checks-button', badges: ['Run Night Audit'], badgesFromLeft: 0);
});

test('sheet with a blocker', function () use ($endTheDay, $takeRoomAway) {
    $endTheDay();
    $takeRoomAway->call($this);

    $page = $this->open(route('dashboard', $this->property), 1100)
        ->waitForText('Run Night Audit')
        ->click('Run Night Audit')
        ->waitForText('Fix before running')
        ->wait(1);

    $this->shoot($page, 'readiness-checks-sheet', badges: [
        'Fix before running',
        'Assign a room',
        'Needs a look',
        'If you leave it: marked no-show, and the no-show fee is charged.',
        'Check in',
        'Mark no-show',
        'Extend 1 night',
    ], frame: '[role="dialog"]');
});

test('sheet with warnings only', function () use ($endTheDay, $addFolioWarnings) {
    $endTheDay();
    $addFolioWarnings->call($this);

    $page = $this->open(route('dashboard', $this->property), 1050)
        ->waitForText('Run Night Audit')
        ->click('Run Night Audit')
        ->waitForText('Needs a look')
        ->wait(1);

    $this->shoot($page, 'readiness-checks-warnings', badges: [
        'Folio still open after check-out',
        'Master folio not billed',
        'Money held on a closed booking',
        'Open folio',
    ], frame: '[role="dialog"]');
});

test('mark no-show asks first', function () use ($endTheDay) {
    $endTheDay();

    $page = $this->open(route('dashboard', $this->property), 1100)
        ->waitForText('Run Night Audit')
        ->click('Run Night Audit')
        ->waitForText('Needs a look')
        ->click('Mark no-show')
        ->waitForText('Mark this unit as no-show?')
        ->wait(1);

    $this->shoot($page, 'readiness-checks-no-show', badges: ['Back'], frame: '[role="dialog"]');
});

test('check out over the sheet', function () use ($endTheDay) {
    $endTheDay();

    $page = $this->open(route('dashboard', $this->property), 1100)
        ->waitForText('Run Night Audit')
        ->click('Run Night Audit')
        ->waitForText('Needs a look')
        ->click('Check out')
        ->waitForText('Check out ·')
        ->wait(1);

    $this->shoot($page, 'readiness-checks-check-out', frame: '[role="dialog"]');
});
