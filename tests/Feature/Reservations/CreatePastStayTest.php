<?php

require_once __DIR__.'/../Support/PastStaySetup.php';

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreatePastStay;
use App\Data\Reservations\NewBookingData;
use App\Enums\BookingSourceType;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomStatus;
use App\Services\Folio\FolioBalance;
use Database\Factories\BookingSourceFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 * @param  array<string, mixed>  $data
 */
function createPastStay(array $setup, array $line = [], string $reason = 'Walk-in entered late', array $data = [], bool $checkedOut = false): BookingResult
{
    return app(CreatePastStay::class)($setup['user'], $setup['property'], NewBookingData::from([
        'bookingSource' => BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::WalkIn),
        'units' => [['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-12', 'arrivalTime' => '15:00', 'departureDate' => '2026-10-16', 'adults' => 2, ...$line]],
        'guests' => [['guestId' => $setup['guest']->id, 'unitIndex' => 0]],
        'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][0]->id]],
        ...$data,
    ]), $reason, true, $checkedOut);
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pastStayBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => $setup['walkIn']->id,
        'arrival_date' => '2026-10-12',
        'arrival_time' => '15:00',
        'departure_date' => '2026-10-16',
        'reason' => 'Walk-in entered late',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'master_pays',
        'lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => [], 'room_id' => $setup['rooms'][0]->id]],
        ...$overrides,
    ];
}

test('a past stay is saved, checked in and charged every night before the business date', function () {
    $setup = pastStaySetup();

    $result = createPastStay($setup);

    $unit = $result->reservation->units()->sole();
    $lines = FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->orderBy('service_date')->get();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at->format('Y-m-d H:i'))->toBe('2026-10-12 15:00')
        ->and($lines->map(fn ($line): string => $line->service_date->toDateString().'/'.$line->posting_date->toDateString())->all())->toBe(['2026-10-12/2026-10-14', '2026-10-13/2026-10-14'])
        ->and(Activity::query()->where('event', 'reservation.past_stay_created')->sole()->properties['reason'])->toBe('Walk-in entered late');
});

test('a past stay is refused for a bad date or reason and nothing is saved', function (array $line, string $reason, string $refusal) {
    $setup = pastStaySetup();

    $result = createPastStay($setup, $line, $reason);

    expect($result->refusal)->toBe($refusal)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0);
})->with([
    'arrives today' => [['arrivalDate' => '2026-10-14', 'departureDate' => '2026-10-16'], 'Late', BookingResult::REFUSAL_ARRIVAL_NOT_PAST],
    'began before go-live' => [['arrivalDate' => '2026-10-09'], 'Late', BookingResult::REFUSAL_ARRIVAL_BEFORE_GO_LIVE],
    'already left' => [['departureDate' => '2026-10-14'], 'Late', BookingResult::REFUSAL_DEPARTURE_NOT_AFTER_TODAY],
    'no reason' => [[], '  ', BookingResult::REFUSAL_REASON_REQUIRED],
]);

test('a room that is taken on a past night refuses the whole stay', function () {
    $setup = pastStaySetup();
    pastStayUnit($setup, '2026-10-13', '2026-10-15', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => '2026-10-13 15:00:00']);
    $reservations = Reservation::query()->count();
    $lines = FolioLine::query()->count();

    $result = createPastStay($setup);

    expect($result->isRefused())->toBeTrue()
        ->and(Reservation::query()->count())->toBe($reservations)
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and(ReservationUnit::query()->count())->toBe(1);
});

test('the past stay page shows for a user with folio.manual', function () {
    $setup = pastStaySetup();

    $this->actingAs($setup['user'])
        ->get(route('reservations.past', ['property' => $setup['property']->code]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/create')
            ->where('isPastStay', true)
            ->where('isWalkIn', false)
            ->where('canEnterPastStay', true)
            ->where('goLiveDate', '2026-10-10'));
});

test('a user without folio.manual cannot open or save a past stay', function () {
    $setup = pastStaySetup();
    actionsRoleWith($setup['user'], ['reservations.manage']);
    $url = route('reservations.past', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->get($url)->assertForbidden();
    $this->actingAs($setup['user'])->post($url, pastStayBody($setup))->assertForbidden();
    $this->actingAs($setup['user'])
        ->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page->where('canEnterPastStay', false));
    expect(Reservation::query()->count())->toBe(0);
});

test('saving a past stay opens the folio tab', function () {
    $setup = pastStaySetup();

    $response = $this->actingAs($setup['user'])->post(route('reservations.store-past', ['property' => $setup['property']->code]), pastStayBody($setup));

    $reservation = Reservation::query()->sole();
    $response->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $reservation]));
    expect($reservation->units()->sole()->status)->toBe(UnitStatus::CheckedIn)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->count())->toBe(2);
});

test('a past stay needs a reason and a room', function () {
    $setup = pastStaySetup();
    $url = route('reservations.store-past', ['property' => $setup['property']->code]);
    $noRoom = pastStayBody($setup);
    unset($noRoom['lines'][0]['room_id']);

    $this->actingAs($setup['user'])->post($url, pastStayBody($setup, ['reason' => '']))->assertSessionHasErrors('reason');
    $this->actingAs($setup['user'])->post($url, $noRoom)->assertSessionHasErrors('lines.0.room_id');
    $this->actingAs($setup['user'])->post($url, pastStayBody($setup, ['arrival_date' => '2026-10-14']))->assertSessionHasErrors('arrival_date');
    expect(Reservation::query()->count())->toBe(0);
});

test('an already checked out stay is checked out on its departure date with the folio left open', function () {
    $setup = pastStaySetup();

    $result = createPastStay($setup, ['arrivalDate' => '2026-10-11', 'departureDate' => '2026-10-13', 'departureTime' => '11:00'], checkedOut: true);

    expect($result->refusal)->toBeNull();
    $unit = $result->reservation->units()->sole();
    $folio = $result->reservation->folios()->sole();

    expect($result->isRefused())->toBeFalse()
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->checked_out_at->format('Y-m-d H:i'))->toBe('2026-10-13 11:00')
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and(app(FolioBalance::class)->forFolio($folio))->not->toBe('0.00')
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->count())->toBe(2)
        ->and(RoomStatus::query()->where('room_id', $setup['rooms'][0]->id)->value('status'))->not->toBe(HousekeepingStatus::Dirty);
});

test('an already checked out stay cannot depart after the business date', function () {
    $setup = pastStaySetup();

    $result = createPastStay($setup, ['departureDate' => '2026-10-16'], checkedOut: true);

    expect($result->refusal)->toBe(BookingResult::REFUSAL_DEPARTURE_AFTER_TODAY)
        ->and(Reservation::query()->count())->toBe(0);
});

test('saving an already checked out stay opens the folio tab with the settle prompt, and Settle now keeps the reason', function () {
    $setup = pastStaySetup();

    $response = $this->actingAs($setup['user'])->post(route('reservations.store-past', ['property' => $setup['property']->code]), pastStayBody($setup, ['status' => 'checked_out', 'arrival_date' => '2026-10-11', 'departure_date' => '2026-10-13']));

    $reservation = Reservation::query()->sole();
    $folio = $reservation->folios()->sole();
    $manualUrl = route('reservations.folios.manual', ['property' => $setup['property']->code, 'reservation' => $reservation, 'folio' => $folio]);
    $response->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $reservation, 'folio' => $folio->id]));

    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('settlePastStayUrl', $manualUrl));

    $this->get($manualUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('manual.reason', 'Walk-in entered late'));
});

test('the folio tab shows no settle prompt unless a checked out past stay was just saved', function () {
    $setup = pastStaySetup();

    $this->actingAs($setup['user'])->post(route('reservations.store-past', ['property' => $setup['property']->code]), pastStayBody($setup, ['status' => 'checked_out', 'arrival_date' => '2026-10-11', 'departure_date' => '2026-10-13']));

    $reservation = Reservation::query()->sole();
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $reservation]);

    $this->get($url);
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('settlePastStayUrl', null));
});
