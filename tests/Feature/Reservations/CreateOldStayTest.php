<?php

require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateOldStay;
use App\Data\Reservations\NewBookingData;
use App\Enums\BookingSourceType;
use App\Enums\FolioStatus;
use App\Enums\HousekeepingStatus;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\RoomStatus;
use App\Services\Reservations\BookingQuote;
use Carbon\CarbonImmutable;
use Database\Factories\BookingSourceFactory;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * An Old stay of one night (8 to 9 Oct) in the first room, before the go-live date of 10 Oct.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $line
 */
function makeOldStay(array $setup, array $line = [], string $reason = 'Stay before go-live'): BookingResult
{
    return app(CreateOldStay::class)($setup['user'], $setup['property'], NewBookingData::from([
        'bookingSource' => BookingSourceFactory::systemFor($setup['property']->id, BookingSourceType::WalkIn),
        'units' => [['roomTypeId' => $setup['roomType']->id, 'ratePlanId' => $setup['plan']->id, 'arrivalDate' => '2026-10-08', 'arrivalTime' => '15:00', 'departureDate' => '2026-10-09', 'departureTime' => '11:00', 'adults' => 2, ...$line]],
        'guests' => [['guestId' => $setup['guest']->id, 'unitIndex' => 0]],
        'picks' => [['unitIndex' => 0, 'roomId' => $setup['rooms'][0]->id]],
    ]), $reason, true);
}

test('an old stay is checked in and out in the past, posts nothing and leaves every folio closed without an invoice number', function () {
    $setup = migratedStaySetup();

    $result = makeOldStay($setup);

    $unit = $result->reservation->units()->sole();
    expect($result->refusal)->toBeNull()
        ->and($unit->status)->toBe(UnitStatus::CheckedOut)
        ->and($unit->checked_in_at->format('Y-m-d H:i'))->toBe('2026-10-08 15:00')
        ->and($unit->checked_out_at->format('Y-m-d H:i'))->toBe('2026-10-09 11:00')
        ->and($unit->nights()->count())->toBe(1)
        ->and($unit->nights()->value('room_id'))->toBe($setup['rooms'][0]->id)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Folio::query()->count())->toBeGreaterThan(0)
        ->and(Folio::query()->where('status', '!=', FolioStatus::Closed)->count())->toBe(0)
        ->and(Folio::query()->whereNotNull('invoice_number')->count())->toBe(0)
        ->and(RoomStatus::query()->where('room_id', $setup['rooms'][0]->id)->value('status'))->not->toBe(HousekeepingStatus::Dirty);

    $log = Activity::query()->where('event', 'reservation.old_stay_created')->sole();
    expect($log->properties['reason'])->toBe('Stay before go-live')
        ->and($log->properties['units'])->toBe([$unit->id]);
});

test('an old stay is refused for a bad date, reason or a closed window and nothing is saved', function (array $line, string $reason, int $window, string $refusal) {
    $setup = migratedStaySetup($window);

    $result = makeOldStay($setup, $line, $reason);

    expect($result->refusal)->toBe($refusal)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0);
})->with([
    'began on go-live' => [['arrivalDate' => '2026-10-10', 'departureDate' => '2026-10-11'], 'Old', 60, BookingResult::REFUSAL_ARRIVAL_NOT_BEFORE_GO_LIVE],
    'ends on go-live' => [['departureDate' => '2026-10-10'], 'Old', 60, BookingResult::REFUSAL_DEPARTURE_NOT_BEFORE_GO_LIVE],
    'no reason' => [[], '  ', 60, BookingResult::REFUSAL_REASON_REQUIRED],
    'window closed' => [[], 'Old', 3, BookingResult::REFUSAL_MIGRATION_WINDOW_CLOSED],
]);

test('a night that is not free refuses the old stay', function () {
    $setup = migratedStaySetup();
    pastStayUnit($setup, '2026-10-08', '2026-10-09', ['status' => UnitStatus::CheckedOut, 'checked_in_at' => '2026-10-08 15:00:00', 'checked_out_at' => '2026-10-09 11:00:00']);

    $result = makeOldStay($setup);

    expect($result->refusal)->toBe('room_not_free')
        ->and(Reservation::query()->count())->toBe(1);
});

test('the Old stay page opens for folio.manual inside the window and sends everyone else away', function () {
    $setup = migratedStaySetup();
    $url = route('reservations.old', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/create')
            ->where('isOldStay', true)
            ->where('isPastStay', true)
            ->where('isMigratedStay', false)
            ->where('canEnterOldStay', true)
            ->where('goLiveDate', '2026-10-10'));

    actionsRoleWith($setup['user'], ['reservations.manage']);
    $this->get($url)->assertForbidden();
    $this->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page->where('canEnterOldStay', false));
});

test('after the window the Old stay page goes back to Past stay and the mode is hidden', function () {
    $setup = migratedStaySetup(3);

    $this->actingAs($setup['user'])->get(route('reservations.old', ['property' => $setup['property']->code]))
        ->assertRedirect(route('reservations.past', ['property' => $setup['property']->code]));
    $this->get(route('reservations.past', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page->where('canEnterOldStay', false));
});

test('saving an old stay posts to the past route with the old mode and opens the reservation', function () {
    $setup = migratedStaySetup();
    $route = route('reservations.store-past', ['property' => $setup['property']->code]);
    $body = migratedStayBody($setup, ['mode' => 'old', 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-09', 'reason' => 'Stay before go-live']);

    $this->actingAs($setup['user'])->post($route, $body)
        ->assertRedirect(route('reservations.show', ['property' => $setup['property']->code, 'reservation' => Reservation::query()->sole()]));

    expect(FolioLine::query()->count())->toBe(0)
        ->and(Reservation::query()->sole()->units()->sole()->status)->toBe(UnitStatus::CheckedOut);
});

test('a refused old stay goes back to the form with the error and saves nothing', function () {
    $setup = migratedStaySetup();
    $route = route('reservations.store-past', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->post($route, migratedStayBody($setup, ['mode' => 'old', 'arrival_date' => '2026-10-08', 'departure_date' => '2026-10-12']))
        ->assertSessionHasErrors('departure_date');

    expect(Reservation::query()->count())->toBe(0);
});

test('a night before go-live with no rate prices at 0.00, and an old stay and a migrated stay can still be saved', function () {
    $setup = migratedStaySetup();
    Rate::query()->where('date', '<', '2026-10-10')->delete();

    $old = makeOldStay($setup);
    $migrated = createMigratedStay([...$setup, 'rooms' => [$setup['rooms'][1]]], ['arrivalDate' => '2026-10-08']);

    expect($old->refusal)->toBeNull()
        ->and($old->reservation->units()->sole()->nights()->pluck('amount')->all())->toBe(['0.00'])
        ->and($migrated->refusal)->toBeNull()
        ->and($migrated->reservation->units()->sole()->nights()->orderBy('date')->limit(2)->pluck('amount')->all())->toBe(['0.00', '0.00'])
        ->and(nightServiceDates())->toBe(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13']);
});

test('a missing rate from go-live on is still refused', function () {
    $setup = migratedStaySetup();
    Rate::query()->where('date', '>=', '2026-10-11')->delete();

    $result = createMigratedStay($setup, ['arrivalDate' => '2026-10-08']);

    expect($result->refusal)->toBe('rate_missing')
        ->and(Reservation::query()->count())->toBe(0);
});

test('the booking quote shows a night before go-live without a rate as 0.00', function () {
    $setup = migratedStaySetup();
    Rate::query()->where('date', '<', '2026-10-10')->delete();

    $quote = app(BookingQuote::class)->line($setup['property'], $setup['roomType']->id, $setup['plan']->id, CarbonImmutable::parse('2026-10-08'), CarbonImmutable::parse('2026-10-12'), 2, [], 1);

    expect($quote->errors)->toBe([])
        ->and(array_map(fn ($night) => $night->amount, $quote->nights))->toBe(['0.00', '0.00', '100.00', '100.00']);
});
