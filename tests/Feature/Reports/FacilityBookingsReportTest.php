<?php

use App\Data\Reports\ReportFilters;
use App\Enums\FacilityBookedBy;
use App\Enums\FacilityBookingStatus;
use App\Enums\FacilityRateBasis;
use App\Enums\PermissionEnum;
use App\Enums\RemarkKind;
use App\Enums\ReportKey;
use App\Features\Facilities;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationRemark;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * The event setup with an event on 15 Oct 2026, a reservation of one unit in Room 12 from 14 to 16 Oct, the Night
 * Walk (by person, 20 places, 40.00 each) and the Riverside Hall (by item, after the Night Walk in the settings order).
 *
 * @return array{property: Property, owner: User, event: Event, eventFolio: Folio, reservation: Reservation, reservationFolio: Folio, walk: Facility, hall: Facility}
 */
function facilityReportSetup(): array
{
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event, $eventFolio] = makeEvent($property, $owner, ['name' => 'Borneo Trails group', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->create(['arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $room = Room::factory()->create(['property_id' => $property->id, 'room_type_id' => $unit->room_type_id, 'number' => '12']);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['room_id' => $room->id]);
    $reservationFolio = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    $walk = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Night Walk', 'booked_by' => FacilityBookedBy::Person, 'rate_basis' => FacilityRateBasis::Each, 'capacity' => 20, 'position' => 1]);
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall', 'position' => 2]);

    return compact('property', 'owner', 'event', 'eventFolio', 'reservation', 'reservationFolio', 'walk', 'hall');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function facilityReportBooking(array $setup, Facility $facility, bool $forEvent, array $attributes = []): FacilityBooking
{
    return FacilityBooking::factory()->create([
        'property_id' => $setup['property']->id,
        'facility_id' => $facility->id,
        'event_id' => $forEvent ? $setup['event']->id : null,
        'reservation_id' => $forEvent ? null : $setup['reservation']->id,
        'folio_id' => $forEvent ? $setup['eventFolio']->id : $setup['reservationFolio']->id,
        'booking_date' => '2026-10-15',
        'starts_at' => '20:00',
        'ends_at' => '22:00',
        'unit_amount' => '40.00',
        ...$attributes,
    ]);
}

test('rows go by date, facility and time, name the guest or event, and each session totals its places', function () {
    $setup = facilityReportSetup();
    $hall = facilityReportBooking($setup, $setup['hall'], true, ['starts_at' => '18:00', 'ends_at' => '23:00', 'unit_amount' => '500.00']);
    $guest = facilityReportBooking($setup, $setup['walk'], false, ['quantity' => 2, 'notes' => 'Bring torches']);
    $group = facilityReportBooking($setup, $setup['walk'], true, ['quantity' => 15, 'status' => FacilityBookingStatus::Tentative]);
    facilityReportBooking($setup, $setup['walk'], true, ['quantity' => 5, 'status' => FacilityBookingStatus::Cancelled]);
    $nextDay = facilityReportBooking($setup, $setup['walk'], false, ['booking_date' => '2026-10-14']);
    $report = ReportKey::FacilityBookings->report();
    $filters = new ReportFilters('2026-10-14', '2026-10-15');

    $rows = $report->rows($setup['property'], $filters);
    $sections = $report->sections($rows, $filters);

    expect(array_map(fn ($row): int => $row->key, $rows))->toBe([$nextDay->id, $guest->id, $group->id, $hall->id])
        ->and($rows[1]->owner)->toBe($setup['reservation']->booker->full_name)
        ->and($rows[1]->ref)->toBe('12')
        ->and($rows[1]->amount)->toBe('80.00')
        ->and($rows[1]->cells()['notes'])->toBe('Bring torches')
        ->and($rows[2]->owner)->toBe('Borneo Trails group')
        ->and($rows[2]->ref)->toBe($setup['event']->number)
        ->and($rows[2]->status->label)->toBe('Tentative')
        ->and(array_map(fn ($section): array => [$section->title, $section->subtotal], $sections))->toBe([
            ['Night Walk · 14 Oct · 20:00–22:00', '1 / 20 people'],
            ['Night Walk · 15 Oct · 20:00–22:00', '17 / 20 people'],
            ['Riverside Hall · 15 Oct · 18:00–23:00', '1 / 1 item'],
        ]);
});

test('picked facilities and statuses filter the rows, and a picked Cancelled booking does not count in the total', function () {
    $setup = facilityReportSetup();
    facilityReportBooking($setup, $setup['hall'], true);
    $confirmed = facilityReportBooking($setup, $setup['walk'], false, ['quantity' => 2]);
    $cancelled = facilityReportBooking($setup, $setup['walk'], true, ['quantity' => 5, 'status' => FacilityBookingStatus::Cancelled]);
    $report = ReportKey::FacilityBookings->report();
    $filters = new ReportFilters('2026-10-15', '2026-10-15', facilityIds: [$setup['walk']->id], facilityStatuses: ['confirmed', 'cancelled']);

    $rows = $report->rows($setup['property'], $filters);

    expect(array_map(fn ($row): int => $row->key, $rows))->toBe([$confirmed->id, $cancelled->id])
        ->and($report->sections($rows, $filters)[0]->subtotal)->toBe('2 / 20 people');
});

test('the page reads the filters from the URL, and the report is a 404 with the Facilities feature off', function () {
    $setup = facilityReportSetup();
    facilityReportBooking($setup, $setup['walk'], false);
    $viewer = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReportsView])->create();
    $url = "/p/{$setup['property']->code}/reports/facility-bookings";

    $this->actingAs($viewer)->get($url.'?from=2026-10-15&to=2026-10-15&facilities='.$setup['walk']->id.',abc&statuses=cancelled,nope')->assertInertia(fn (Assert $page) => $page
        ->component('reports/facility-bookings')
        ->where('facilities', [(string) $setup['walk']->id])
        ->where('statuses', ['cancelled'])
        ->has('rows.data', 0));

    $this->actingAs($viewer)->get($url.'?from=2026-10-15&to=2026-10-15')->assertInertia(fn (Assert $page) => $page
        ->where('statuses', ['tentative', 'confirmed'])
        ->has('rows.data', 1)
        ->has('sections', 1)
        ->where('facilityOptions.0.label', 'Night Walk'));

    Feature::for($setup['property'])->deactivate(Facilities::class);

    $this->actingAs($viewer)->get($url)->assertNotFound();
});

test('the spreadsheet keeps the date column and the filters', function () {
    $setup = facilityReportSetup();
    facilityReportBooking($setup, $setup['walk'], false, ['quantity' => 2]);
    facilityReportBooking($setup, $setup['hall'], true);

    $response = $this->actingAs($setup['owner'])->post("/p/{$setup['property']->code}/reports/facility-bookings/spreadsheet", [
        'from' => '2026-10-15',
        'to' => '2026-10-15',
        'facilities' => (string) $setup['walk']->id,
        'format' => 'csv',
    ])->assertOk();
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['Date', 'Time', 'Facility', 'Guest / event', 'Room / no.', 'Qty', 'Status', 'Dietary remarks'])
        ->and($rows[1])->toBe(['2026-10-15', '20:00–22:00', 'Night Walk', $setup['reservation']->booker->full_name, '12', '2', 'Confirmed', '']);
});

test('a guest\'s dietary remark shows on the participant list, and an event has none', function () {
    $setup = facilityReportSetup();
    ReservationRemark::factory()->for($setup['reservation'])->create(['kind' => RemarkKind::Dietary, 'body' => 'Vegetarian, no nuts']);
    ReservationRemark::factory()->for($setup['reservation'])->create(['kind' => RemarkKind::Housekeeping, 'body' => 'Extra towels']);
    $guest = facilityReportBooking($setup, $setup['walk'], false);
    $group = facilityReportBooking($setup, $setup['walk'], true);

    $rows = collect(ReportKey::FacilityBookings->report()->rows($setup['property'], new ReportFilters('2026-10-15', '2026-10-15')))->keyBy('key');

    expect($rows[$guest->id]->cells()['dietary'])->toBe('Vegetarian, no nuts')
        ->and($rows[$group->id]->cells()['dietary'])->toBe('')
        ->and(collect(ReportKey::FacilityBookings->columns())->firstWhere('key', 'dietary')->defaultVisible)->toBeTrue();
});
