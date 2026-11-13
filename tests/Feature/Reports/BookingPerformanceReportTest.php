<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function bookingPerformanceUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/booking-performance".$query;
}

/**
 * A reservation made at `$createdAt` (stored in UTC).
 *
 * @param  array<string, mixed>  $attributes
 */
function bookingPerformanceReservation(Property $property, string $createdAt, ?string $state = null, array $attributes = []): Reservation
{
    $factory = Reservation::factory();
    $factory = match ($state) {
        'ota' => $factory->ota(),
        'walkIn' => $factory->walkIn(),
        default => $factory,
    };

    return $factory->create(['property_id' => $property->id, 'created_at' => $createdAt, ...$attributes]);
}

/**
 * An overnight unit with one 100.00 night per night of the stay.
 *
 * @param  array<string, mixed>  $attributes
 */
function bookingPerformanceUnit(Reservation $reservation, RoomType $roomType, string $arrival, string $departure, ?string $state = null, array $attributes = []): ReservationUnit
{
    $factory = ReservationUnit::factory()->for($reservation)->withNights();
    $factory = match ($state) {
        'cancelled' => $factory->cancelled(),
        'noShow' => $factory->noShow(),
        default => $factory,
    };

    return $factory->create(['room_type_id' => $roomType->id, 'arrival_date' => $arrival, 'departure_date' => $departure, ...$attributes]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function bookingPerformanceRows(mixed $rows): array
{
    return collect($rows)->mapWithKeys(fn (array $row) => [$row['group'] => [$row['bookings'], $row['room_nights'], $row['revenue'], $row['adr'], $row['lead_time']]])->all();
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();

    $this->actingAs($front)->get(bookingPerformanceUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(bookingPerformanceUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(bookingPerformanceUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/booking-performance')
        ->where('maxRangeDays', 366)
        ->where('group', 'room_type')
        ->where('sort', '-revenue')
        ->has('groupOptions', 5)
        ->has('layout.columns', 6));
});

test('room type counts per unit, and the total counts each booking once', function () {
    [$user, $property] = reportSetup();
    [, $other] = reportSetup();
    $deluxe = RoomType::factory()->for($property)->create(['name' => 'Deluxe']);
    $twin = RoomType::factory()->for($property)->create(['name' => 'Twin']);

    $both = bookingPerformanceReservation($property, '2026-10-05 04:00:00');
    bookingPerformanceUnit($both, $deluxe, '2026-10-20', '2026-10-22');
    bookingPerformanceUnit($both, $twin, '2026-10-20', '2026-10-21');
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-06 04:00:00'), $deluxe, '2026-10-10', '2026-10-13');

    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-06 04:00:00'), $deluxe, '2026-10-20', '2026-10-22', 'cancelled');
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-09-20 04:00:00'), $deluxe, '2026-10-20', '2026-10-22');
    ReservationUnit::factory()->for(bookingPerformanceReservation($property, '2026-10-06 04:00:00'))->dayUse('2026-10-12')->create(['room_type_id' => $deluxe->id]);
    bookingPerformanceUnit(bookingPerformanceReservation($other, '2026-10-06 04:00:00'), RoomType::factory()->for($other)->create(['name' => 'Deluxe']), '2026-10-20', '2026-10-22');

    $this->actingAs($user)->get(bookingPerformanceUrl($property, '?from=2026-10-01&to=2026-10-10'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => bookingPerformanceRows($rows) === [
            'Deluxe' => [2, 5, '500.00', '100.00', 10],
            'Twin' => [1, 1, '100.00', '100.00', 15],
        ])
        ->where('rows.data.0.key', "room_type:{$deluxe->id}")
        ->missing('rows.data.0.reservationIds')
        ->where('totals.figures', [
            'group' => null,
            'bookings' => 2,
            'room_nights' => 6,
            'revenue' => '600.00',
            'adr' => '100.00',
            'lead_time' => 11,
        ]));
});

test('the booking date is read in the property time zone', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $roomType = RoomType::factory()->for($property)->create();

    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-08 17:00:00'), $roomType, '2026-10-20', '2026-10-21');
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-09 16:30:00'), $roomType, '2026-10-20', '2026-10-21');
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-08 15:30:00'), $roomType, '2026-10-20', '2026-10-21');

    $this->actingAs($user)->get(bookingPerformanceUrl($property, '?from=2026-10-09&to=2026-10-09&group=day'))->assertInertia(fn (Assert $page) => $page
        ->where('sort', 'group')
        ->where('rows.data', fn ($rows) => bookingPerformanceRows($rows) === ['2026-10-09' => [1, 1, '100.00', '100.00', 11]]));
});

test('walk-ins have no lead time, and the total lead time leaves them out', function () {
    [$user, $property] = reportSetup();
    $roomType = RoomType::factory()->for($property)->create();

    $ota = bookingPerformanceReservation($property, '2026-10-01 04:00:00', 'ota');
    bookingPerformanceUnit($ota, $roomType, '2026-10-11', '2026-10-12');
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-01 04:00:00', 'ota'), $roomType, '2026-10-05', '2026-10-06');
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-01 04:00:00', 'walkIn'), $roomType, '2026-10-01', '2026-10-03');

    $this->actingAs($user)->get(bookingPerformanceUrl($property, '?from=2026-10-01&to=2026-10-10&group=source&sort=group'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => bookingPerformanceRows($rows) === [
            'Booking.com' => [2, 2, '200.00', '100.00', 7],
            'Walk-in' => [1, 2, '200.00', '100.00', null],
        ])
        ->where('rows.data.0.source.name', 'Booking.com')
        ->where('totals.figures.lead_time', 7));
});

test('cancelled and no-show bookings count only when grouped by status', function () {
    [$user, $property] = reportSetup();
    $roomType = RoomType::factory()->for($property)->create();
    $plan = RatePlan::factory()->for($property)->create(['name' => 'Flexible']);

    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-05 04:00:00'), $roomType, '2026-10-20', '2026-10-22', attributes: ['rate_plan_id' => $plan->id]);
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-05 04:00:00'), $roomType, '2026-10-20', '2026-10-21', 'cancelled', ['rate_plan_id' => $plan->id]);
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-05 04:00:00'), $roomType, '2026-10-08', '2026-10-09', 'noShow', ['rate_plan_id' => $plan->id]);

    $this->actingAs($user)->get(bookingPerformanceUrl($property, '?from=2026-10-01&to=2026-10-10&group=rate_plan'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => bookingPerformanceRows($rows) === ['Flexible' => [1, 2, '200.00', '100.00', 15]]));

    $this->actingAs($user)->get(bookingPerformanceUrl($property, '?from=2026-10-01&to=2026-10-10&group=status&sort=group'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => bookingPerformanceRows($rows) === [
            'Cancelled' => [1, 1, '100.00', '100.00', 15],
            'Confirmed' => [1, 2, '200.00', '100.00', 15],
            'No-show' => [1, 1, '100.00', '100.00', 3],
        ])
        ->where('rows.data.0.status.value', 'cancelled')
        ->where('totals.figures.bookings', 3));
});

test('the PDF and the spreadsheet export the grouped rows', function () {
    [$user, $property] = reportSetup();
    bookingPerformanceUnit(bookingPerformanceReservation($property, '2026-10-05 04:00:00', 'ota'), RoomType::factory()->for($property)->create(), '2026-10-20', '2026-10-22');
    $body = ['from' => '2026-10-01', 'to' => '2026-10-10', 'group' => 'source'];

    $this->actingAs($user)->post(bookingPerformanceUrl($property, '/pdf'), $body)->assertOk();
    $csv = $this->actingAs($user)->post(bookingPerformanceUrl($property, '/spreadsheet'), [...$body, 'format' => 'csv']);

    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Booking.com')->toContain('200');
});
