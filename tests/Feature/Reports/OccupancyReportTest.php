<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\DailyStatistic;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function occupancySetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function occupancySnapshot(Property $property, string $date, array $attributes = []): DailyStatistic
{
    return DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => $date, ...$attributes]);
}

function occupancyUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/occupancy".$query;
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = occupancySetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = occupancySetup();
    [, $other] = occupancySetup();
    occupancySnapshot($own, '2026-10-09');
    occupancySnapshot($other, '2026-10-08');

    $this->actingAs($front)->get(occupancyUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(occupancyUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(occupancyUrl($own, '?from=2026-10-01&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/occupancy')
        ->where('maxRangeDays', 366)
        ->where('sort', 'date')
        ->has('layout.columns', 13)
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', '2026-10-09'));
});

test('closed dates read the snapshot with the room revenue of the ledger, bed and room units together', function () {
    [$user, $property] = occupancySetup();
    occupancySnapshot($property, '2026-10-08', ['rooms_available' => 8, 'rooms_sold' => 3, 'beds_available' => 4, 'beds_sold' => 1, 'occupancy' => '33.33', 'adr' => '75.00', 'revpar' => '25.00', 'arrivals' => 2, 'departures' => 1, 'no_shows' => 1, 'in_house_guests' => 5]);
    $room = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    $folio = Folio::factory()->create(['property_id' => $property->id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id, 'amount' => '300.00', 'unit_amount' => '300.00', 'posting_date' => '2026-10-08']);

    $this->actingAs($user)->get(occupancyUrl($property, '?from=2026-10-08&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', '2026-10-08')
        ->where('rows.data.0.live', false)
        ->where('rows.data.0.available', 12)
        ->where('rows.data.0.sold', 4)
        ->where('rows.data.0.occupancy', '33.33')
        ->where('rows.data.0.adr', '75.00')
        ->where('rows.data.0.revpar', '25.00')
        ->where('rows.data.0.revenue', '300.00')
        ->where('rows.data.0.arrivals', 2)
        ->where('rows.data.0.noshows', 1)
        ->where('rows.data.0.guests', 5));
});

test('the open date is worked out live and flagged, and later dates are left out', function () {
    [$user, $property] = occupancySetup();
    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->count(4)->for($property)->create(['room_type_id' => $roomType->id]);
    $unit = ReservationUnit::factory()
        ->for(Reservation::factory()->create(['property_id' => $property->id]))
        ->withNights()
        ->checkedIn()
        ->create(['room_type_id' => $roomType->id, 'arrival_date' => '2026-10-10', 'departure_date' => '2026-10-11']);
    $unit->nights()->update(['room_id' => Room::query()->where('property_id', $property->id)->first()->id]);
    occupancySnapshot($property, '2026-10-09');
    occupancySnapshot($property, '2026-10-10', ['rooms_sold' => 9, 'rooms_available' => 9]);
    occupancySnapshot($property, '2026-10-11');

    $this->actingAs($user)->get(occupancyUrl($property, '?from=2026-10-09&to=2026-10-12'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 2)
        ->where('rows.data.0.live', false)
        ->where('rows.data.1.key', '2026-10-10')
        ->where('rows.data.1.live', true)
        ->where('rows.data.1.available', 4)
        ->where('rows.data.1.sold', 1)
        ->where('rows.data.1.occupancy', '25.00'));
});

test('a closed date without a snapshot is left out', function () {
    [$user, $property] = occupancySetup();
    occupancySnapshot($property, '2026-10-07');

    $this->actingAs($user)->get(occupancyUrl($property, '?from=2026-10-05&to=2026-10-08'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', '2026-10-07'));
});

test('the totals add the counts and work the ratios out again from the sums', function () {
    [$user, $property] = occupancySetup();
    $room = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create();
    $folio = Folio::factory()->create(['property_id' => $property->id]);
    occupancySnapshot($property, '2026-10-07', ['rooms_available' => 10, 'rooms_sold' => 10, 'arrivals' => 4, 'departures' => 1, 'no_shows' => 1]);
    occupancySnapshot($property, '2026-10-08', ['rooms_available' => 10, 'rooms_sold' => 0, 'arrivals' => 1, 'departures' => 3, 'no_shows' => 0]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id, 'amount' => '1000.00', 'unit_amount' => '1000.00', 'posting_date' => '2026-10-07']);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $room->id, 'amount' => '234.00', 'unit_amount' => '234.00', 'posting_date' => '2026-10-08']);

    $this->actingAs($user)->get(occupancyUrl($property, '?from=2026-10-07&to=2026-10-08'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.available', 20)
        ->where('totals.figures.sold', 10)
        ->where('totals.figures.occupancy', '50.00')
        ->where('totals.figures.revenue', '1234.00')
        ->where('totals.figures.adr', '123.40')
        ->where('totals.figures.revpar', '61.70')
        ->where('totals.figures.arrivals', 5)
        ->where('totals.figures.departures', 4)
        ->where('totals.figures.noshows', 1)
        ->where('totals.figures.guests', null));
});

test('day use is shown apart and summed, and occupancy, ADR and RevPAR ignore it', function () {
    [$user, $property] = occupancySetup();
    occupancySnapshot($property, '2026-10-08', ['rooms_available' => 10, 'rooms_sold' => 5, 'occupancy' => '50.00', 'adr' => '100.00', 'day_use_sold' => 2, 'day_use_revenue' => '190.00']);
    occupancySnapshot($property, '2026-10-09', ['rooms_available' => 10, 'rooms_sold' => 0, 'occupancy' => '0.00', 'adr' => '0.00', 'day_use_sold' => 1, 'day_use_revenue' => '60.50']);

    $this->actingAs($user)->get(occupancyUrl($property, '?from=2026-10-08&to=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.day_use_sold', 2)
        ->where('rows.data.0.day_use_revenue', '190.00')
        ->where('rows.data.0.occupancy', '50.00')
        ->where('totals.figures.day_use_sold', 3)
        ->where('totals.figures.day_use_revenue', '250.50')
        ->where('totals.figures.occupancy', '25.00'));
});

test('the PDF and CSV end with the total row, and the selection totals only the selected dates', function () {
    [$user, $property] = occupancySetup();
    occupancySnapshot($property, '2026-10-07', ['rooms_available' => 10, 'rooms_sold' => 5, 'arrivals' => 2]);
    occupancySnapshot($property, '2026-10-08', ['rooms_available' => 10, 'rooms_sold' => 10, 'arrivals' => 3]);
    $base = ['from' => '2026-10-07', 'to' => '2026-10-08', 'rowKeys' => ['2026-10-08']];
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/occupancy/pdf", $base)->assertOk();
    expect($captured->data['rows'])->toHaveCount(1)
        ->and($captured->data['totals']->figures['sold'])->toBe(10)
        ->and($captured->data['totals']->figures['occupancy'])->toBe('100.00');

    $response = $this->actingAs($user)->post("/p/{$property->code}/reports/occupancy/spreadsheet", [...$base, 'format' => 'csv']);
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('2026-10-08')
        ->and($rows[2][0])->toBe('Total')
        ->and($rows[2][2])->toBe('10')
        ->and($rows[2][3])->toBe('100');
});
