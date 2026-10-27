<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Data\Reports\CheckedOutRow;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Services\Reports\CheckedOutReport;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function checkedOutUnit(Property $property, string $departure = '2026-10-10', array $attributes = []): ReservationUnit
{
    $booking = Reservation::factory()->create(['property_id' => $property->id]);

    return ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => date('Y-m-d', strtotime($departure.' -2 days')),
        'departure_date' => $departure,
        'status' => UnitStatus::CheckedOut,
        'checked_in_at' => now()->subDays(2),
        'checked_out_at' => now(),
        ...$attributes,
    ]);
}

function checkedOutUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/guest-list?tab=checked-out".($query === '' ? '' : '&'.ltrim($query, '?'));
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = reportSetup();
    [, $other] = reportSetup();
    $mine = checkedOutUnit($own);
    checkedOutUnit($other);

    $this->actingAs($front)->get(checkedOutUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(checkedOutUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(checkedOutUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/guest-list')
        ->has('rows.data', 1)
        ->where('rows.data.0.key', $mine->id));
});

test('only checked out units with a departure in the range are listed', function () {
    [$user, $property] = reportSetup();
    $inRange = checkedOutUnit($property, '2026-10-10');
    checkedOutUnit($property, '2026-10-11');
    checkedOutUnit($property, '2026-10-10', ['status' => UnitStatus::CheckedIn, 'checked_out_at' => null]);
    checkedOutUnit($property, '2026-10-10', ['status' => UnitStatus::Cancelled, 'checked_out_at' => null]);

    $this->actingAs($user)->get(checkedOutUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('maxRangeDays', 31)
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', $inRange->id));
});

test('the default sort is the highest balance first and the default columns hide the rest', function () {
    [$user, $property] = reportSetup();
    checkedOutUnit($property);

    $this->actingAs($user)->get(checkedOutUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('sort', '-balance')
        ->has('layout.columns', 14)
        ->where('layout.columns', fn ($columns) => collect($columns)->where('visible', true)->pluck('key')->values()->all() === ['reservation', 'guest', 'room', 'checked_out_at', 'balance']));

    $keys = array_map(fn ($column): string => $column->key, ReportKey::CheckedOut->columns());

    expect($keys)->not->toContain('by');
});

test('the room is the room of the last night and the time is in the property time zone', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = checkedOutUnit($property, '2026-10-10', ['checked_out_at' => '2026-10-10 02:30:00']);
    $first = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '101']);
    $last = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '202']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-08', 'room_id' => $first->id]);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-09', 'room_id' => $last->id]);

    $this->actingAs($user)->get(checkedOutUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.room', '202')
        ->where('rows.data.0.checked_out_at', '2026-10-10 10:30'));
});

test('the report returns only the requested row keys', function () {
    [, $property] = reportSetup();
    $first = checkedOutUnit($property);
    checkedOutUnit($property);
    $report = app(CheckedOutReport::class);
    $filters = new ReportFilters('2026-10-10', '2026-10-10');

    expect(array_map(fn (CheckedOutRow $row): int => $row->key, $report->rows($property, $filters, [$first->id])))->toBe([$first->id])
        ->and($report->rows($property, $filters, []))->toBe([])
        ->and(ReportKey::CheckedOut->report())->toBeInstanceOf(CheckedOutReport::class);
});

test('a range is allowed up to 31 days', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->get(checkedOutUrl($property, '?from=2026-10-01&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($user)->get(checkedOutUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertSessionDoesntHaveErrors();
});

test('the PDF and the CSV export the checked out units', function () {
    [$user, $property] = reportSetup();
    checkedOutUnit($property);
    checkedOutUnit($property, '2026-10-20');
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->rows = $view->getData()['rows'];
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/checked-out/pdf", ['from' => '2026-10-10', 'to' => '2026-10-10'])->assertOk();
    $csv = $this->actingAs($user)->post("/p/{$property->code}/reports/checked-out/spreadsheet", ['format' => 'csv', 'from' => '2026-10-10', 'to' => '2026-10-10']);

    expect($captured->rows)->toHaveCount(1)
        ->and($csv->headers->get('Content-Disposition'))->toContain('checked-out-2026-10-10.csv');

    $lines = array_values(array_filter(explode("\n", ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))));

    expect(str_getcsv($lines[0]))->toBe(['Booking', 'Guest', 'Room', 'Checked out', 'Balance'])
        ->and($lines)->toHaveCount(2);
});

test('the default sort puts the highest balance first', function () {
    [$user, $property] = reportSetup();
    $paid = checkedOutUnit($property);
    $owing = checkedOutUnit($property);
    $folio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $owing->reservation_id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => TransactionKind::Charge, 'amount' => '250.00', 'unit_amount' => '250.00', 'posting_date' => '2026-10-09']);

    $this->actingAs($user)->get(checkedOutUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.key', $owing->id)
        ->where('rows.data.0.balance', '250.00')
        ->where('rows.data.1.key', $paid->id)
        ->where('rows.data.1.balance', '0.00'));
});

test('sorting by the check-out time orders the dates, not the text', function () {
    [$user, $property] = reportSetup();
    $ninth = checkedOutUnit($property, '2026-10-09', ['checked_out_at' => '2026-10-09 08:00:00']);
    $fourteenth = checkedOutUnit($property, '2026-10-14', ['checked_out_at' => '2026-10-14 08:00:00']);

    $this->actingAs($user)->get(checkedOutUrl($property, '?from=2026-10-09&to=2026-10-14&sort=checked_out_at'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.key', $ninth->id)
        ->where('rows.data.1.key', $fourteenth->id));

    $this->actingAs($user)->get(checkedOutUrl($property, '?from=2026-10-09&to=2026-10-14&sort=-checked_out_at'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.key', $fourteenth->id));
});
