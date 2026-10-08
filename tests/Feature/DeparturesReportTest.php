<?php

use App\Data\Reports\DepartureRow;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reports\DeparturesReport;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function departuresSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
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
function departingUnit(Property $property, string $departure = '2026-10-10', array $attributes = []): ReservationUnit
{
    $booking = Reservation::factory()->create(['property_id' => $property->id]);

    return ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => date('Y-m-d', strtotime($departure.' -2 days')),
        'departure_date' => $departure,
        ...$attributes,
    ]);
}

function departuresUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/departures".$query;
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = departuresSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = departuresSetup();
    [, $other] = departuresSetup();
    $mine = departingUnit($own);
    departingUnit($other);

    $this->actingAs($front)->get(departuresUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(departuresUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(departuresUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/departures')
        ->has('rows.data', 1)
        ->where('rows.data.0.key', $mine->id));
});

test('the page opens on the business date with the departure columns and sort', function () {
    [$user, $property] = departuresSetup();
    departingUnit($property, '2026-10-10');
    departingUnit($property, '2026-10-11');

    $this->actingAs($user)->get(departuresUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('maxRangeDays', 31)
        ->where('sort', 'departure')
        ->has('layout.columns', 15)
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.departure', '2026-10-10')
        ->where('rows.data.0.nights', 2));
});

test('only units that hold inventory depart', function () {
    [$user, $property] = departuresSetup();
    departingUnit($property, '2026-10-10', ['status' => UnitStatus::Cancelled]);
    departingUnit($property, '2026-10-10', ['status' => UnitStatus::NoShow]);
    departingUnit($property, '2026-10-10', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
    departingUnit($property, '2026-10-10', ['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()]);
    departingUnit($property, '2026-10-10');

    $this->actingAs($user)->get(departuresUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 3));
});

test('the room is the room of the last night', function () {
    [$user, $property] = departuresSetup();
    $unit = departingUnit($property, '2026-10-10');
    $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Aisyah Rahman']);
    UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id]);
    $first = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '101']);
    $last = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '202']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-08', 'room_id' => $first->id]);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-09', 'room_id' => $last->id]);

    $this->actingAs($user)->get(departuresUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.guest', 'Aisyah Rahman')
        ->where('rows.data.0.unit', "{$unit->roomType->name} / 202"));
});

test('a range is allowed up to 31 days and checked like Arrivals', function () {
    [$user, $property] = departuresSetup();

    $this->actingAs($user)->get(departuresUrl($property, '?from=2026-10-01&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($user)->get(departuresUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertSessionDoesntHaveErrors();
});

test('the report returns only the requested row keys', function () {
    [, $property] = departuresSetup();
    $first = departingUnit($property);
    departingUnit($property);
    $report = app(DeparturesReport::class);
    $filters = new ReportFilters('2026-10-10', '2026-10-10');

    expect(array_map(fn (DepartureRow $row): int => $row->key, $report->rows($property, $filters, [$first->id])))->toBe([$first->id])
        ->and($report->rows($property, $filters, []))->toBe([])
        ->and(ReportKey::Departures->report())->toBeInstanceOf(DeparturesReport::class);
});

test('the PDF and the CSV export the departures', function () {
    [$user, $property] = departuresSetup();
    departingUnit($property);
    departingUnit($property, '2026-10-20');
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->rows = $view->getData()['rows'];
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/departures/pdf", ['from' => '2026-10-10', 'to' => '2026-10-10'])->assertOk();
    $csv = $this->actingAs($user)->post("/p/{$property->code}/reports/departures/spreadsheet", ['format' => 'csv', 'from' => '2026-10-10', 'to' => '2026-10-10']);

    expect($captured->rows)->toHaveCount(1)
        ->and($csv->headers->get('Content-Disposition'))->toContain('departures-2026-10-10.csv');

    $lines = array_values(array_filter(explode("\n", ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))));

    expect(str_getcsv($lines[0]))->toBe(['Reservation no.', 'Guest', 'Departure', 'Departure time', 'Nights', 'Room type / room', 'Status', 'Balance'])
        ->and($lines)->toHaveCount(2);
});

test('the split columns hold the room type, the room of the last night, the guest counts and the departure time', function () {
    [, $property] = departuresSetup();
    $unit = departingUnit($property, '2026-10-10', ['adults' => 2, 'children' => 3, 'departure_time' => '10:45:00']);
    $room = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '202']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-09', 'room_id' => $room->id]);
    $unassigned = departingUnit($property, '2026-10-10');

    $rows = collect(app(DeparturesReport::class)->rows($property, new ReportFilters('2026-10-10', '2026-10-10')))->keyBy('key');

    expect($rows[$unit->id]->cells())->toMatchArray(['room_type' => $unit->roomType->name, 'room' => '202', 'adults' => '2', 'children' => '3', 'departure_time' => '10:45'])
        ->and($rows[$unit->id]->values())->toMatchArray(['adults' => 2, 'children' => 3, 'departure_time' => '10:45'])
        ->and($rows[$unassigned->id]->room)->toBeNull()
        ->and($rows[$unassigned->id]->cells()['room'])->toBe('');

    $defaults = collect(ReportKey::Departures->columns())->mapWithKeys(fn ($column): array => [$column->key => $column->defaultVisible]);

    expect($defaults->only(['room_type', 'room', 'adults', 'children'])->contains(true))->toBeFalse()
        ->and($defaults['departure_time'])->toBeTrue();
});
