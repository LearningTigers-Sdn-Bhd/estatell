<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Actions\FrontDesk\CancelUnit;
use App\Actions\FrontDesk\ChangeUnitDates;
use App\Actions\FrontDesk\MarkNoShow;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\PolicyScope;
use App\Enums\ReportKey;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\DailyStatistic;
use App\Models\KioskWalkInHold;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Reports\BookingChangesReport;
use App\Services\Reports\RoomsOnBooksOverview;
use App\Services\Reports\RoomsOnBooksReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(4, 0));
});

function roomsOnBooksUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/rooms-on-books".$query;
}

/**
 * A room type with five rooms, the rate plan the units use, and the cancellation and no-show policies the front desk
 * needs to cancel or mark a no-show.
 *
 * @return array{roomType: RoomType, plan: RatePlan}
 */
function roomsOnBooksSetup(Property $property): array
{
    foreach ([SystemTransactionCode::CancellationRevenue, SystemTransactionCode::NoShowRevenue] as $code) {
        TransactionCode::factory()->for($property)->system($code)->create();
        RoomRevenuePolicy::factory()->for($property)->fixed('50.00')->forCode($code, PolicyScope::WholeStay)->create();
    }

    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(5)->for($property)->create(['room_type_id' => $roomType->id]);

    return ['roomType' => $roomType, 'plan' => RatePlan::factory()->for($property)->create()];
}

/**
 * A unit at 100.00 a night, booked a week before the frozen day unless `created_at` is given.
 *
 * @param  array{roomType: RoomType, plan: RatePlan}  $setup
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $reservation
 */
function roomsOnBooksUnit(Property $property, array $setup, string $arrival, string $departure, array $attributes = [], array $reservation = [], ?string $state = null): ReservationUnit
{
    return ReservationUnit::factory()
        ->when($state !== null, fn ($factory) => $factory->{$state}())
        ->for(Reservation::factory()->create(['property_id' => $property->id, ...$reservation]))
        ->withNights()
        ->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => $arrival, 'departure_date' => $departure, 'created_at' => '2026-10-03 04:00:00', ...$attributes]);
}

test('the page needs reports.view, is scoped to the property and opens each tab on its own dates', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();

    $this->actingAs($front)->get(roomsOnBooksUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(roomsOnBooksUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(roomsOnBooksUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/rooms-on-books')
        ->where('tab', 'overview')
        ->has('tabs', 3)
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('overview.asOf', '2026-10-10')
        ->has('overview.impact', 5)
        ->has('overview.pace', 4)
        ->has('overview.lengthOfStay', 4)
        ->missing('rows'));
    $this->actingAs($manager)->get(roomsOnBooksUrl($own, '?tab=on-the-books'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-24')
        ->where('rows.meta.total', 15)
        ->where('layout.orientation', 'landscape'));
    $this->actingAs($manager)->get(roomsOnBooksUrl($own, '?tab=changes'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('sections', [])
        ->where('rows.meta.total', 0));
});

test('on the books counts the rooms full that morning, the movements and the booked rate of the paid nights', function () {
    [, $property] = reportSetup();
    $setup = roomsOnBooksSetup($property);
    roomsOnBooksUnit($property, $setup, '2026-10-09', '2026-10-11', state: 'checkedIn');
    roomsOnBooksUnit($property, $setup, '2026-10-10', '2026-10-12', reservation: ['group_name' => 'Birding club']);
    $comp = roomsOnBooksUnit($property, $setup, '2026-10-11', '2026-10-12', ['rate_plan_id' => RatePlan::factory()->for($property)->complimentary()->create()->id]);
    $comp->nights()->update(['base_amount' => '0.00', 'amount' => '0.00']);
    roomsOnBooksUnit($property, $setup, '2026-10-10', '2026-10-11', ['status' => UnitStatus::Cancelled]);

    $rows = collect(app(RoomsOnBooksReport::class)->rows($property, new ReportFilters('2026-10-10', '2026-10-11')))->keyBy('key');

    expect($rows['2026-10-10']->toArray())->toMatchArray([
        'in_house' => 1,
        'arrivals' => 1,
        'departures' => 0,
        'group_arrivals' => 1,
        'rooms_on_books' => 2,
        'complimentary' => 0,
        'available' => 5,
        'occupancy' => '40.00',
        'arr' => '100.00',
        'room_revenue' => '200.00',
    ])->and($rows['2026-10-11']->toArray())->toMatchArray([
        'in_house' => 2,
        'arrivals' => 1,
        'departures' => 1,
        'group_arrivals' => 0,
        'rooms_on_books' => 2,
        'complimentary' => 1,
        'arr' => '100.00',
        'room_revenue' => '100.00',
    ])->and(ReportKey::OnTheBooks->totals($rows->values()->all())->figures)->toMatchArray([
        'in_house' => null,
        'arrivals' => 2,
        'rooms_on_books' => 4,
        'occupancy' => '40.00',
        'arr' => '100.00',
        'room_revenue' => '300.00',
    ]);
});

test('changes list the bookings made, cancelled, moved and missed in the dates, in sections, with their impact', function () {
    [$manager, $property] = reportSetup();
    $setup = roomsOnBooksSetup($property);
    roomsOnBooksUnit($property, $setup, '2026-10-12', '2026-10-15', ['created_at' => now()], ['created_by' => $manager->id]);
    $released = roomsOnBooksUnit($property, $setup, '2026-10-10', '2026-10-11', ['created_at' => now(), 'status' => UnitStatus::Cancelled]);
    KioskWalkInHold::factory()->create(['property_id' => $property->id, 'reservation_unit_id' => $released->id, 'released_at' => now()]);
    $cancelled = roomsOnBooksUnit($property, $setup, '2026-10-20', '2026-10-22');
    $missed = roomsOnBooksUnit($property, $setup, '2026-10-10', '2026-10-11');
    $moved = roomsOnBooksUnit($property, $setup, '2026-10-15', '2026-10-18');
    roomsOnBooksUnit($property, $setup, '2026-10-12', '2026-10-13', ['created_at' => now()->subDay()]);
    $actor = User::factory()->create(['property_id' => $property->id]);

    expect(app(CancelUnit::class)($actor, $cancelled, doNotCharge: true, reason: 'Flight cancelled')->isRefused())->toBeFalse()
        ->and(app(MarkNoShow::class)($actor, $missed, doNotCharge: true, reason: 'Did not arrive')->isRefused())->toBeFalse()
        ->and(app(ChangeUnitDates::class)($actor, $moved, $moved->arrival_date, CarbonImmutable::parse('2026-10-17'))->isRefused())->toBeFalse();

    [, $other] = reportSetup();
    $elsewhere = roomsOnBooksUnit($other, roomsOnBooksSetup($other), '2026-10-20', '2026-10-21');
    app(CancelUnit::class)(User::factory()->create(['property_id' => $other->id]), $elsewhere, doNotCharge: true, reason: 'Other hotel');

    $filters = new ReportFilters('2026-10-10', '2026-10-10');
    $report = app(BookingChangesReport::class);
    $rows = $report->rows($property, $filters);

    expect(array_map(fn ($row): array => [$row->kind->value, $row->nights_change, $row->revenue_change, $row->reason], $rows))->toBe([
        ['new', 3, '300.00', null],
        ['cancellation', -2, '-200.00', 'Flight cancelled'],
        ['amendment', -1, '-100.00', null],
        ['no-show', -1, '-100.00', 'Did not arrive'],
    ])->and($rows[0]->booked_by)->toBe($manager->name)
        ->and($rows[0]->changed_at)->toStartWith('2026-10-10 ')
        ->and([$rows[2]->previous_departure, $rows[2]->departure, $rows[2]->nights, $rows[2]->revenue])->toBe(['2026-10-18', '2026-10-17', 2, '200.00'])
        ->and(array_map(fn ($section): string => $section->title, $report->sections($rows, $filters)))->toBe(['New bookings', 'Cancellations', 'Amendments', 'No-shows'])
        ->and($report->sections($rows, $filters)[3]->subtotal)->toBe('1 room · -1 room night · -100.00')
        ->and($report->totals($rows)->figures)->toMatchArray(['nights_change' => -1, 'revenue_change' => '-100.00'])
        ->and($report->rows($property, $filters, [$rows[1]->key]))->toHaveCount(1);
});

test('the overview sums the changes, the pace from the business date, the last closed dates and the pick-up by length of stay', function () {
    [, $property] = reportSetup();
    $setup = roomsOnBooksSetup($property);
    roomsOnBooksUnit($property, $setup, '2026-10-10', '2026-10-12', ['created_at' => now()]);
    roomsOnBooksUnit($property, $setup, '2026-10-25', '2026-11-03', ['created_at' => now()]);
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-09', 'rooms_available' => 5, 'rooms_sold' => 2, 'beds_available' => 0, 'beds_sold' => 0]);
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => '2026-09-29', 'rooms_available' => 5, 'rooms_sold' => 5, 'beds_available' => 0, 'beds_sold' => 0]);

    $overview = app(RoomsOnBooksOverview::class)->forDates($property, new ReportFilters('2026-10-10', '2026-10-10'), CarbonImmutable::parse('2026-10-10'));

    expect(collect($overview->impact)->map->toArray()->keyBy('label')->all())->toMatchArray([
        'New bookings' => ['kind' => 'new', 'label' => 'New bookings', 'rooms' => 2, 'nights' => 11, 'revenue' => '1100.00', 'arr' => '100.00'],
        'Net' => ['kind' => null, 'label' => 'Net', 'rooms' => 2, 'nights' => 11, 'revenue' => '1100.00', 'arr' => '100.00'],
    ])->and(collect($overview->pace)->map(fn ($line): array => [$line->nights, $line->rpd, $line->occupancy])->all())->toBe([
        [2, '0.20', '4.00'],
        [5, '0.50', '10.00'],
        [4, '0.40', '8.00'],
        [11, '0.37', '7.33'],
    ])->and(collect($overview->recent)->map(fn ($day): array => [$day->date, $day->sold, $day->occupancy])->all())->toBe([
        ['2026-10-09', 2, '40.00'],
    ])->and(collect($overview->lengthOfStay)->map(fn ($band): array => [$band->rooms, $band->nights])->all())->toBe([
        [1, 2], [0, 0], [1, 9], [0, 0],
    ]);
});

test('the grid, the changes and the overview cost the same number of queries however many bookings the dates hold', function () {
    [, $property] = reportSetup();
    $setup = roomsOnBooksSetup($property);
    $actor = User::factory()->create(['property_id' => $property->id]);
    $count = function () use ($property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(RoomsOnBooksReport::class)->rows($property, new ReportFilters('2026-10-10', '2026-10-24'));
        app(BookingChangesReport::class)->rows($property, new ReportFilters('2026-10-10', '2026-10-10'));
        app(RoomsOnBooksOverview::class)->forDates($property, new ReportFilters('2026-10-10', '2026-10-10'), CarbonImmutable::parse('2026-10-10'));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $book = function () use ($property, $setup, $actor): void {
        roomsOnBooksUnit($property, $setup, '2026-10-12', '2026-10-14', ['created_at' => now()]);
        app(CancelUnit::class)($actor, roomsOnBooksUnit($property, $setup, '2026-10-15', '2026-10-16'), doNotCharge: true, reason: 'Changed plans');
    };

    $book();
    Model::preventLazyLoading();

    try {
        $count();
        $few = $count();

        foreach (range(1, 3) as $ignored) {
            $book();
        }

        $many = $count();
    } finally {
        Model::preventLazyLoading(false);
    }

    expect($many)->toBe($few);
});

test('both tables export to PDF and spreadsheet, the changes in their sections', function () {
    [$manager, $property] = reportSetup();
    $setup = roomsOnBooksSetup($property);
    roomsOnBooksUnit($property, $setup, '2026-10-12', '2026-10-14', ['created_at' => now()]);
    $dates = ['from' => '2026-10-10', 'to' => '2026-10-10'];

    $this->actingAs($manager)->post("/p/{$property->code}/reports/on-the-books/pdf", ['from' => '2026-10-10', 'to' => '2026-10-24'])->assertOk();
    $this->actingAs($manager)->post("/p/{$property->code}/reports/booking-changes/pdf", $dates)->assertOk();

    $csv = $this->actingAs($manager)->post("/p/{$property->code}/reports/booking-changes/spreadsheet", [...$dates, 'format' => 'csv'])->assertOk()->streamedContent();

    expect($csv)->toContain('"New booking"')->toContain('2026-10-12,2026-10-14,2,100,200,2,200');
});
