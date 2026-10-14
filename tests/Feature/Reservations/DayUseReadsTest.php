<?php

require_once __DIR__.'/../Support/DayUseSetup.php';

use App\Actions\NightAudit\PostRoomCharges;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Services\Reservations\BookingSummary;
use App\Services\Reservations\StayLabel;
use App\Services\Reservations\StayTotal;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

test('the stay total and the credit check count the day use block', function () {
    $setup = dayUseHttpSetup();
    $result = createDayUseReservation($setup, [dayUseLine($setup), dayUseLine($setup, ['roomId' => $setup['rooms'][1]->id, 'hours' => 2])]);

    expect((new StayTotal)->forReservation($result->reservation))->toBe('160.00');

    ReservationUnit::query()->orderBy('id')->first()->forceFill(['status' => 'cancelled'])->save();

    expect((new StayTotal)->forReservation($result->reservation))->toBe('60.00');
});

test('the reservation list shows the block price, the day use label and the room', function () {
    $setup = dayUseHttpSetup();
    createDayUseReservation($setup, [dayUseLine($setup)]);

    $this->actingAs($setup['user'])->get(route('reservations.index', $setup['property']->code))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.total', '100.00')
        ->where('reservations.data.0.nights', 0)
        ->where('reservations.data.0.stayType', 'day_use')
        ->where('reservations.data.0.stayLabel', 'Day use · 4h')
        ->where('reservations.data.0.roomBox.numbers', [$setup['rooms'][0]->number]));
});

test('an overnight reservation keeps its nights and has no day use label', function () {
    $setup = dayUseHttpSetup();
    createDayUseReservation($setup, [['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['basic']->id, 'arrivalDate' => $setup['date']->toDateString(), 'departureDate' => $setup['date']->addDays(2)->toDateString(), 'adults' => 2]]);

    $this->actingAs($setup['user'])->get(route('reservations.index', $setup['property']->code))->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data.0.total', '200.00')
        ->where('reservations.data.0.nights', 2)
        ->where('reservations.data.0.stayType', 'overnight')
        ->where('reservations.data.0.stayLabel', null));
});

test('the reservation page reads Day use 4h with the block as the total, never 0 nights', function () {
    $setup = dayUseHttpSetup();
    $result = createDayUseReservation($setup, [dayUseLine($setup)]);

    $this->actingAs($setup['user'])->get(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => $result->reservation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reservation.stayTotal', '100.00')
            ->where('reservation.roomNights', 0)
            ->where('reservation.stayType', 'day_use')
            ->where('reservation.stayLabel', 'Day use · 4h')
            ->where('unit.stayType', 'day_use')
            ->where('unit.stayLabel', 'Day use · 4h')
            ->where('unit.nightCount', 0)
            ->where('unit.total', '100.00')
            ->where('unit.assigned', [$setup['rooms'][0]->number])
            ->where('unit.allNightsAssigned', true)
            ->where('unit.actions', [])
            ->where('unit.arrivalTime', '10:00')
            ->where('unit.departureTime', '14:00'));
});

test('hours that differ read as Day use alone and an overnight unit has no label', function () {
    $setup = dayUseHttpSetup();
    $result = createDayUseReservation($setup, [dayUseLine($setup), dayUseLine($setup, ['roomId' => $setup['rooms'][1]->id, 'hours' => 2])]);
    $units = $result->reservation->units()->get();
    $labels = new StayLabel;

    expect($labels->forUnits($units))->toBe('Day use')
        ->and($labels->forUnit($units[0]))->toBe('Day use · 4h')
        ->and($labels->forUnit($units[1]))->toBe('Day use · 2h')
        ->and(StayLabel::dayUse(3))->toBe('Day use · 3h')
        ->and($labels->forUnits([ReservationUnit::factory()->create()]))->toBeNull();
});

test('the room charge step of the night audit posts nothing for a day use unit', function () {
    $setup = dayUseHttpSetup();
    createDayUseReservation($setup, [dayUseLine($setup)]);
    $unit = ReservationUnit::query()->sole();

    $result = app(PostRoomCharges::class)(null, $unit, $setup['date']);

    expect(FolioLine::query()->count())->toBe(0)->and(Folio::query()->count())->toBe(0)->and($result)->not->toBeNull();
});

test('the booking summary reads the stay as a date and Day use 4h', function () {
    $setup = dayUseHttpSetup();
    seedDayUseCodes($setup['property']);
    $result = createDayUseReservation($setup, [dayUseLine($setup)]);

    $document = app(BookingSummary::class)->for($setup['property'], $result->reservation);
    $stay = collect($document->strip)->firstWhere('label', 'Stay');

    expect($stay['value'])->toBe($setup['date']->format('d M Y').' · Day use · 4h');
});
