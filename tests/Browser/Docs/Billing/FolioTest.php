<?php

use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReverseLine;
use App\Actions\Reservations\ChangeNightPrice;
use App\Data\Folio\NewChargeData;
use App\Models\ChargeItem;
use App\Models\UnitNight;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the folio tab with its rail', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G1')]))
        ->waitForText('Projected total')
        ->wait(1);

    $this->shoot($page, 'folio-rail', badges: [
        'Master',
        'Unit 202',
        'Balance',
        'Projected total',
        'Posted lines',
        'Print',
        'More actions',
        'Post charge',
        'Posted',
        'Scheduled',
    ], highlight: ['button[aria-label="Hide summary"]']);
});

test('a unit that has not started', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('G10')]))
        ->waitForText('Projected total')
        ->wait(1);

    $this->shoot($page, 'folio-not-started', badges: ['Unit 201', 'Record payment']);
});

test('a closed folio', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S1')]))
        ->waitForText('Reopen folio')
        ->wait(1);

    $this->shoot($page, 'folio-closed', badges: ['Print invoice', 'Reopen folio']);
});

test('the row markers', function () {
    $reservation = $this->scenario('S9');
    $unit = $reservation->units()->firstOrFail();
    $folio = $reservation->folios()->firstOrFail();
    $item = ChargeItem::query()->where('property_id', $this->property->id)->where('name', 'Laundry bag')->firstOrFail();

    $charge = app(PostCharge::class)(auth()->user(), $unit, new NewChargeData(
        chargeItemId: $item->id,
        transactionCodeId: null,
        description: null,
        quantity: 1,
        unitAmount: '25.00',
        idempotencyKey: 'manual:'.Str::uuid(),
        folioId: $folio->id,
    ));
    app(ReverseLine::class)(auth()->user(), $charge->line());

    $night = UnitNight::query()->where('reservation_unit_id', $unit->id)->whereDate('date', '2026-10-14')->firstOrFail();
    app(ChangeNightPrice::class)(auth()->user(), $unit, [$night], '150.00', 'Loyal guest');

    $page = $this->open(route('reservations.folio', [$this->property, $reservation]), height: 1400)
        ->waitForText('Projected total')
        ->wait(1);

    $this->shoot($page, 'folio-markers', element: 'table');
});
