<?php

use App\Data\Reports\InHouseRow;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitDayUse;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reports\InHouseReport;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function inHouseSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
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
 * @param  array<string, mixed>  $reservation
 */
function stayingUnit(Property $property, string $arrival, string $departure, array $attributes = [], array $reservation = []): ReservationUnit
{
    $booking = Reservation::factory()->create(['property_id' => $property->id, ...$reservation]);

    return ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'status' => UnitStatus::CheckedIn,
        'checked_in_at' => now(),
        ...$attributes,
    ]);
}

function inHouseUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/in-house".$query;
}

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = inHouseSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = inHouseSetup();
    [, $other] = inHouseSetup();
    $mine = stayingUnit($own, '2026-10-09', '2026-10-12');
    stayingUnit($other, '2026-10-09', '2026-10-12');

    $this->actingAs($front)->get(inHouseUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(inHouseUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(inHouseUrl($own))->assertInertia(fn (Assert $page) => $page
        ->component('reports/in-house')
        ->has('rows.data', 1)
        ->where('rows.data.0.key', $mine->id));
});

test('the page opens on the business date and a date in the URL replaces it', function () {
    [$user, $property] = inHouseSetup();
    stayingUnit($property, '2026-10-10', '2026-10-12');
    stayingUnit($property, '2026-10-14', '2026-10-15');

    $this->actingAs($user)->get(inHouseUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('to', '2026-10-10')
        ->where('maxRangeDays', null)
        ->where('sort', 'room')
        ->has('layout.columns', 13)
        ->where('rows.meta.total', 1));

    $this->actingAs($user)->get(inHouseUrl($property, '?date=2026-10-14'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-14')
        ->where('to', '2026-10-14')
        ->where('rows.meta.total', 1));
});

test('the arrival day counts and the departure day does not', function () {
    [$user, $property] = inHouseSetup();
    $arriving = stayingUnit($property, '2026-10-10', '2026-10-12');
    $staying = stayingUnit($property, '2026-10-08', '2026-10-11');
    stayingUnit($property, '2026-10-08', '2026-10-10');
    stayingUnit($property, '2026-10-11', '2026-10-12');

    $this->actingAs($user)->get(inHouseUrl($property, '?sort=reservation'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 2)
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->sort()->values()->all() === [$arriving->id, $staying->id]));
});

test('only checked-in and checked-out units stay, and an early check-out leaves the later dates', function () {
    [$user, $property] = inHouseSetup();
    stayingUnit($property, '2026-10-09', '2026-10-12', ['status' => UnitStatus::Confirmed, 'checked_in_at' => null]);
    stayingUnit($property, '2026-10-09', '2026-10-12', ['status' => UnitStatus::Cancelled]);
    stayingUnit($property, '2026-10-09', '2026-10-12', ['status' => UnitStatus::NoShow]);
    stayingUnit($property, '2026-10-09', '2026-10-12', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()]);
    $left = stayingUnit($property, '2026-10-08', '2026-10-10', ['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDays(2), 'checked_out_at' => now()]);

    $this->actingAs($user)->get(inHouseUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 1));
    $this->actingAs($user)->get(inHouseUrl($property, '?date=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 2)
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('key')->contains($left->id)));
});

test('each row shows the room of that night, the company and the balance', function () {
    [$user, $property] = inHouseSetup();
    $company = Company::factory()->for($property)->create(['name' => 'Borneo Travel']);
    $unit = stayingUnit($property, '2026-10-09', '2026-10-12', ['adults' => 2, 'children' => 1], ['company_id' => $company->id]);
    $first = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '101']);
    $second = Room::factory()->for($property)->create(['room_type_id' => $unit->room_type_id, 'number' => '202']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-09', 'room_id' => $first->id]);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => '2026-10-10', 'room_id' => $second->id]);
    $folio = Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $unit->reservation_id]);
    FolioLine::factory()->create(['folio_id' => $folio->id, 'amount' => '120.00', 'unit_amount' => '120.00']);

    $this->actingAs($user)->get(inHouseUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.room', '202')
        ->where('rows.data.0.arrival', '2026-10-09')
        ->where('rows.data.0.departure', '2026-10-12')
        ->where('rows.data.0.guests', '2 / 1')
        ->where('rows.data.0.company', 'Borneo Travel')
        ->where('rows.data.0.balance', '120.00'));
    $this->actingAs($user)->get(inHouseUrl($property, '?date=2026-10-09'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.room', '101'));
});

test('the date param is checked and from and to are ignored', function () {
    [$user, $property] = inHouseSetup();
    stayingUnit($property, '2026-10-10', '2026-10-12');

    $this->actingAs($user)->get(inHouseUrl($property, '?date=10-10-2026'))->assertSessionHasErrors('date');
    $this->actingAs($user)->get(inHouseUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('from', '2026-10-10')
        ->where('rows.meta.total', 1));
});

test('a day use unit is in-house on its date with its hours as the stay, and not on other dates', function () {
    [, $property] = inHouseSetup();
    $room = Room::factory()->for($property)->create();
    $dayUse = stayingUnit($property, '2026-10-10', '2026-10-10', ['stay_type' => StayType::DayUse, 'arrival_time' => '10:00', 'departure_time' => '14:00']);
    UnitDayUse::factory()->create(['reservation_unit_id' => $dayUse->id, 'date' => '2026-10-10', 'room_id' => $room->id, 'starts_at' => '10:00', 'ends_at' => '14:00']);
    $overnight = stayingUnit($property, '2026-10-09', '2026-10-12');
    $cancelled = stayingUnit($property, '2026-10-10', '2026-10-10', ['stay_type' => StayType::DayUse, 'status' => UnitStatus::Cancelled]);
    $report = app(InHouseReport::class);

    $rows = $report->rows($property, new ReportFilters('2026-10-10', '2026-10-10'));
    $other = $report->rows($property, new ReportFilters('2026-10-11', '2026-10-11'));
    $byKey = collect($rows)->keyBy('key');

    expect($byKey->keys()->all())->toEqualCanonicalizing([$dayUse->id, $overnight->id])
        ->and($byKey[$dayUse->id]->stay)->toBe('Day use · 10:00–14:00')
        ->and($byKey[$dayUse->id]->room)->toBe($room->number)
        ->and($byKey[$overnight->id]->stay)->toBeNull()
        ->and(collect($other)->pluck('key')->all())->toBe([$overnight->id])
        ->and($cancelled->id)->not->toBeIn($byKey->keys()->all());
});

test('the report returns only the requested row keys and is in the registry', function () {
    [, $property] = inHouseSetup();
    $first = stayingUnit($property, '2026-10-09', '2026-10-12');
    stayingUnit($property, '2026-10-09', '2026-10-12');
    $report = app(InHouseReport::class);
    $filters = new ReportFilters('2026-10-10', '2026-10-10');
    $keys = array_map(fn ($column): string => $column->key, ReportKey::InHouse->columns());

    expect(array_map(fn (InHouseRow $row): int => $row->key, $report->rows($property, $filters, [$first->id])))->toBe([$first->id])
        ->and($keys)->toBe(['reservation', 'guest', 'room', 'arrival', 'arrival_time', 'departure', 'departure_time', 'stay', 'guests', 'adults', 'children', 'company', 'balance'])
        ->and(ReportKey::InHouse->maxRangeDays())->toBeNull()
        ->and(ReportKey::InHouse->report())->toBeInstanceOf(InHouseReport::class);
});

test('the PDF and the CSV export the date and the layout preview accepts it', function () {
    [$user, $property] = inHouseSetup();
    stayingUnit($property, '2026-10-09', '2026-10-12');
    stayingUnit($property, '2026-10-14', '2026-10-15');
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->rows = $view->getData()['rows'];
    });

    $this->actingAs($user)->post("/p/{$property->code}/reports/in-house/pdf", ['date' => '2026-10-10'])->assertOk();
    $pdfRows = $captured->rows;
    $csv = $this->actingAs($user)->post("/p/{$property->code}/reports/in-house/spreadsheet", ['format' => 'csv', 'date' => '2026-10-14']);
    $this->actingAs($user)->post("/p/{$property->code}/reports/in-house/layout/preview", [
        'date' => '2026-10-10',
        'orientation' => 'portrait',
        'columns' => array_map(fn ($column): array => ['key' => $column->key, 'visible' => true], ReportKey::InHouse->columns()),
    ])->assertOk();

    expect($pdfRows)->toHaveCount(1)
        ->and($csv->headers->get('Content-Disposition'))->toContain('in-house-2026-10-14.csv')
        ->and(array_values(array_filter(explode("\n", ltrim($csv->streamedContent(), "\xEF\xBB\xBF")))))->toHaveCount(3);

    $this->actingAs($user)->postJson("/p/{$property->code}/reports/in-house/spreadsheet", ['format' => 'csv', 'date' => 'bogus'])->assertJsonValidationErrors('date');
});

test('the split columns and the totals count adults and children, and the time columns are hidden by default', function () {
    [$user, $property] = inHouseSetup();
    stayingUnit($property, '2026-10-09', '2026-10-12', ['adults' => 2, 'children' => 1, 'arrival_time' => '13:00:00', 'departure_time' => '11:30:00']);
    stayingUnit($property, '2026-10-09', '2026-10-12', ['adults' => 3, 'children' => 0]);
    $rows = app(InHouseReport::class)->rows($property, new ReportFilters('2026-10-10', '2026-10-10'));

    expect($rows[0]->cells())->toMatchArray(['guests' => '2 / 1', 'adults' => '2', 'children' => '1', 'arrival_time' => '13:00', 'departure_time' => '11:30'])
        ->and($rows[0]->values())->toMatchArray(['adults' => 2, 'children' => 1])
        ->and(ReportKey::InHouse->totals($rows)->figures)->toMatchArray(['adults' => 5, 'children' => 1, 'guests' => null, 'balance' => null]);

    $defaults = collect(ReportKey::InHouse->columns())->mapWithKeys(fn ($column): array => [$column->key => $column->defaultVisible]);

    expect($defaults->only(['arrival_time', 'departure_time', 'adults', 'children'])->contains(true))->toBeFalse()
        ->and($defaults['guests'])->toBeTrue();

    $this->actingAs($user)->get(inHouseUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.adults', 5)
        ->where('totals.figures.children', 1));
});
