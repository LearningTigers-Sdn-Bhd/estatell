<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Data\Reports\NonNationalGuestRow;
use App\Data\Reports\PoliceReportRow;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\StayType;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reports\NonNationalGuestsReport;
use App\Services\Reports\PoliceReport;
use App\Services\Reservations\UnitNationality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A checked-in unit whose booker has the given nationality.
 *
 * @param  array<string, mixed>  $attributes
 */
function registerUnit(Property $property, string $arrival, string $departure, ?string $nationality = 'SG', array $attributes = []): ReservationUnit
{
    $booker = Guest::factory()->create(['property_id' => $property->id, 'nationality_code' => $nationality]);
    $booking = Reservation::factory()->create(['property_id' => $property->id, 'booker_guest_id' => $booker->id]);

    return ReservationUnit::factory()->for($booking)->create([
        'arrival_date' => $arrival,
        'departure_date' => $departure,
        'status' => UnitStatus::CheckedIn,
        'checked_in_at' => now(),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function registerGuest(ReservationUnit $unit, array $attributes = [], bool $primary = false): Guest
{
    $guest = Guest::factory()->create(['property_id' => $unit->property_id, ...$attributes]);
    UnitGuest::factory()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id, 'is_primary' => $primary]);

    return $guest;
}

function registerDocument(Guest $guest, string $type, string $number, ?string $createdAt = null): GuestIdentityDocument
{
    $document = GuestIdentityDocument::factory()->create(['guest_id' => $guest->id, 'type' => $type, 'number' => $number]);

    if ($createdAt !== null) {
        DB::table('guest_identity_documents')->where('id', $document->id)->update(['created_at' => $createdAt]);
    }

    return $document;
}

/**
 * A tourism tax line under a charge that names the unit, on a folio of the unit's reservation.
 */
function registerTourismTax(ReservationUnit $unit, Tax $tax, string $amount, string $date, ?FolioLine $existingCharge = null, string $sourceType = 'unit'): FolioLine
{
    $folio = Folio::query()->where('reservation_id', $unit->reservation_id)->first()
        ?? Folio::factory()->create(['property_id' => $unit->property_id, 'reservation_id' => $unit->reservation_id]);
    $source = $sourceType === 'night'
        ? UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => $date])
        : $unit;
    $charge = $existingCharge ?? FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'kind' => TransactionKind::Charge,
        'amount' => '100.00',
        'unit_amount' => '100.00',
        'posting_date' => $date,
        'source_type' => $source->getMorphClass(),
        'source_id' => $source->getKey(),
    ]);

    return FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'transaction_code_id' => $tax->transaction_code_id,
        'kind' => TransactionKind::Tax,
        'amount' => $amount,
        'unit_amount' => $amount,
        'posting_date' => $date,
        'parent_line_id' => $charge->id,
    ]);
}

function foreignTax(Property $property): Tax
{
    return Tax::factory()->create(['property_id' => $property->id, 'foreign_guests_only' => true]);
}

function nonNationalUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/non-national-guests".$query;
}

function policeUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/police-report".$query;
}

/**
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function registerRange(array $keys = []): array
{
    return ['from' => '2026-10-01', 'to' => '2026-10-31', ...($keys === [] ? [] : ['rowKeys' => $keys])];
}

/**
 * Posts to the PDF route and returns the rows the view got.
 *
 * @param  array<string, mixed>  $data
 * @return list<array<string, string>>
 */
function registerPdfCells(mixed $test, User $user, Property $property, string $slug, array $data): array
{
    $captured = new stdClass;
    View::composer('pdf.report', function ($view) use ($captured): void {
        $captured->rows = array_map(fn ($row): array => $row->cells(), $view->getData()['rows']);
    });
    $test->actingAs($user)->post("/p/{$property->code}/reports/{$slug}/pdf", $data)->assertOk();

    return $captured->rows;
}

/**
 * @param  array<string, mixed>  $data
 * @return list<list<string>>
 */
function registerCsv(mixed $test, User $user, Property $property, string $slug, array $data): array
{
    $csv = $test->actingAs($user)->post("/p/{$property->code}/reports/{$slug}/spreadsheet", ['format' => 'csv', ...$data])->assertOk();

    return array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv->streamedContent(), "\xEF\xBB\xBF"))))));
}

test('both pages need reports.view and are scoped to the property', function () {
    [$front, $frontProperty] = reportSetup(DefaultRoleEnum::FrontDesk, ['time_zone' => 'Asia/Kuala_Lumpur']);
    [$manager, $own] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    [, $other] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $mine = registerUnit($own, '2026-10-09', '2026-10-12');
    registerUnit($other, '2026-10-09', '2026-10-12');

    $this->actingAs($front)->get(nonNationalUrl($frontProperty))->assertForbidden();
    $this->actingAs($front)->get(policeUrl($frontProperty))->assertForbidden();
    $this->actingAs($manager)->get(nonNationalUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(policeUrl($other))->assertNotFound();
    $this->actingAs($manager)->get(nonNationalUrl($own, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/non-national-guests')
        ->where('sort', 'arrival')
        ->has('rows.data', 1)
        ->where('rows.data.0.key', $mine->id));
    $this->actingAs($manager)->get(policeUrl($own, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/police-report')
        ->where('sort', 'room')
        ->has('rows.data', 1)
        ->where('rows.data.0.key', $mine->id.':'.$mine->reservation->booker_guest_id));
});

test('a unit is foreign when its nationality is set and is not MY', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $foreign = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerUnit($property, '2026-10-09', '2026-10-12', 'MY');
    registerUnit($property, '2026-10-09', '2026-10-12', null);

    expect(UnitNationality::isForeign('SG'))->toBeTrue()
        ->and(UnitNationality::isForeign('my'))->toBeFalse()
        ->and(UnitNationality::isForeign(' MY '))->toBeFalse()
        ->and(UnitNationality::isForeign(''))->toBeFalse()
        ->and(UnitNationality::isForeign(null))->toBeFalse();

    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', $foreign->id)
        ->where('rows.data.0.nationality', 'Singapore'));
});

test('the unit nationality is the primary guest, with the booker as the fallback', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $byPrimary = registerUnit($property, '2026-10-09', '2026-10-12', 'MY');
    registerGuest($byPrimary, ['nationality_code' => 'JP'], primary: true);
    registerGuest($byPrimary, ['nationality_code' => 'MY']);
    $malaysianPrimary = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerGuest($malaysianPrimary, ['nationality_code' => 'MY'], primary: true);
    $byBooker = registerUnit($property, '2026-10-09', '2026-10-12', 'ID');
    registerGuest($byBooker, ['nationality_code' => 'MY']);
    $filters = new ReportFilters('2026-10-01', '2026-10-31');

    $rows = collect(app(NonNationalGuestsReport::class)->rows($property, $filters))->keyBy('key');

    expect($rows->keys()->sort()->values()->all())->toBe([$byPrimary->id, $byBooker->id])
        ->and($rows[$byPrimary->id]->nationality)->toBe('Japan')
        ->and($rows[$byPrimary->id]->guest)->not->toBe($byPrimary->reservation->booker->full_name)
        ->and($rows[$byBooker->id]->nationality)->toBe('Indonesia')
        ->and($rows[$byBooker->id]->guest)->toBe($byBooker->reservation->booker->full_name);
});

test('only checked-in and checked-out units that overlap the dates are listed', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $in = registerUnit($property, '2026-10-09', '2026-10-12');
    $out = registerUnit($property, '2026-10-05', '2026-10-08', 'SG', ['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()]);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['status' => UnitStatus::Confirmed, 'checked_in_at' => null]);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['status' => UnitStatus::Cancelled]);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['status' => UnitStatus::NoShow]);
    registerUnit($property, '2026-09-20', '2026-10-05');
    registerUnit($property, '2026-10-20', '2026-10-22');
    $filters = new ReportFilters('2026-10-06', '2026-10-19');

    $keys = collect(app(NonNationalGuestsReport::class)->rows($property, $filters))->pluck('key')->sort()->values()->all();

    expect($keys)->toBe([$in->id, $out->id])
        ->and(collect(app(PoliceReport::class)->rows($property, $filters))->pluck('status.value')->unique()->sort()->values()->all())->toBe(['checked_in', 'checked_out']);
});

test('a day use unit is listed on its date and in no other range', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $dayUse = registerUnit($property, '2026-10-10', '2026-10-10', 'SG', ['stay_type' => StayType::DayUse, 'status' => UnitStatus::CheckedOut, 'checked_out_at' => now()]);

    $inRange = new ReportFilters('2026-10-08', '2026-10-12');
    $elsewhere = new ReportFilters('2026-10-11', '2026-10-15');

    expect(collect(app(NonNationalGuestsReport::class)->rows($property, $inRange))->pluck('key')->all())->toBe([$dayUse->id])
        ->and(collect(app(PoliceReport::class)->rows($property, $inRange))->pluck('key')->all())->toBe(["{$dayUse->id}:{$dayUse->reservation->booker_guest_id}"])
        ->and(app(NonNationalGuestsReport::class)->rows($property, $elsewhere))->toBe([]);
});

test('nights count only the nights of the stay inside the dates, and the totals sum them', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $inside = registerUnit($property, '2026-10-10', '2026-10-13');
    $startsBefore = registerUnit($property, '2026-09-28', '2026-10-03');
    $endsAfter = registerUnit($property, '2026-10-29', '2026-11-04');
    $tax = foreignTax($property);
    registerTourismTax($inside, $tax, '10.00', '2026-10-10');
    registerTourismTax($endsAfter, $tax, '5.50', '2026-10-30');

    $rows = collect(app(NonNationalGuestsReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-31')))->keyBy('key');
    $report = app(NonNationalGuestsReport::class);

    expect($rows[$inside->id]->nights)->toBe(3)
        ->and($rows[$startsBefore->id]->nights)->toBe(2)
        ->and($rows[$endsAfter->id]->nights)->toBe(3)
        ->and($report->totals($rows->values()->all())->figures)->toMatchArray(['nights' => 8, 'tourism_tax' => '15.50', 'guest' => null]);

    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.nights', 8)
        ->where('totals.figures.tourism_tax', '15.50'));
});

test('tourism tax is summed for each unit for the dates only, with reversals and unit night sources', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-02', '2026-10-06');
    $other = registerUnit($property, '2026-10-02', '2026-10-06');
    $tax = foreignTax($property);
    $localTax = Tax::factory()->create(['property_id' => $property->id, 'foreign_guests_only' => false]);
    $charge = registerTourismTax($unit, $tax, '10.00', '2026-10-02')->parent;
    registerTourismTax($unit, $tax, '10.00', '2026-10-03', null, 'night');
    registerTourismTax($unit, $tax, '10.00', '2026-09-30');
    registerTourismTax($unit, $tax, '10.00', '2026-11-02');
    registerTourismTax($unit, $localTax, '6.00', '2026-10-02', $charge);
    registerTourismTax($other, $tax, '3.00', '2026-10-04');
    $reversedCharge = FolioLine::factory()->create([
        'folio_id' => $charge->folio_id,
        'kind' => TransactionKind::Charge,
        'amount' => '-100.00',
        'unit_amount' => '-100.00',
        'posting_date' => '2026-10-05',
        'source_type' => $charge->source_type,
        'source_id' => $charge->source_id,
    ]);
    registerTourismTax($unit, $tax, '-10.00', '2026-10-05', $reversedCharge);

    $rows = collect(app(NonNationalGuestsReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-31')))->keyBy('key');
    $narrow = collect(app(NonNationalGuestsReport::class)->rows($property, new ReportFilters('2026-10-02', '2026-10-02')))->keyBy('key');

    expect($rows[$unit->id]->tourism_tax)->toBe('10.00')
        ->and($rows[$other->id]->tourism_tax)->toBe('3.00')
        ->and($narrow[$unit->id]->tourism_tax)->toBe('10.00')
        ->and($narrow[$other->id]->tourism_tax)->toBe('0.00');
});

test('tourism tax of another property is not counted', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    [, $other] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-02', '2026-10-06');
    $foreignUnit = registerUnit($other, '2026-10-02', '2026-10-06');
    registerTourismTax($foreignUnit, foreignTax($other), '99.00', '2026-10-03');
    registerTourismTax($unit, foreignTax($property), '4.00', '2026-10-03');

    $rows = app(NonNationalGuestsReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-31'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->tourism_tax)->toBe('4.00');
});

test('the note counts the units with no nationality and is empty when there are none', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    $first = registerUnit($property, '2026-10-09', '2026-10-12', null);
    $report = app(NonNationalGuestsReport::class);
    $filters = new ReportFilters('2026-10-01', '2026-10-31');

    expect($report->note($property, $filters))->toBe('1 unit has no nationality. Its tourism tax may be missing.');

    registerUnit($property, '2026-10-09', '2026-10-12', null);
    registerUnit($property, '2026-12-09', '2026-12-12', null);

    expect($report->note($property, $filters))->toBe('2 units have no nationality. Their tourism tax may be missing.');
    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('note', '2 units have no nationality. Their tourism tax may be missing.'));

    registerGuest($first, ['nationality_code' => 'MY'], primary: true);
    ReservationUnit::query()->where('arrival_date', '2026-10-09')->get()->each(fn (ReservationUnit $unit) => $unit->reservation->booker->forceFill(['nationality_code' => 'SG'])->save());

    expect($report->note($property, $filters))->toBeNull();
    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('note', null));
});

test('the police report has one row for each attached guest and the booker when none is attached', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $family = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    $primary = registerGuest($family, ['nationality_code' => 'SG'], primary: true);
    $second = registerGuest($family, ['nationality_code' => 'MY']);
    $alone = registerUnit($property, '2026-10-09', '2026-10-12', 'ID');
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['status' => UnitStatus::Confirmed, 'checked_in_at' => null]);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['status' => UnitStatus::Cancelled]);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['status' => UnitStatus::NoShow]);

    $rows = collect(app(PoliceReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-31')));

    expect($rows->pluck('key')->sort()->values()->all())->toBe([
        $family->id.':'.$primary->id,
        $family->id.':'.$second->id,
        $alone->id.':'.$alone->reservation->booker_guest_id,
    ])
        ->and($rows->firstWhere('key', $family->id.':'.$second->id)->nationality)->toBe('Malaysia');
});

test('the police report lists every nationality and the foreign only filter keeps foreign guests', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'MY');
    $local = registerGuest($unit, ['nationality_code' => 'MY'], primary: true);
    $abroad = registerGuest($unit, ['nationality_code' => 'GB']);
    registerGuest($unit, ['nationality_code' => null]);
    $range = '?from=2026-10-01&to=2026-10-31';

    $this->actingAs($user)->get(policeUrl($property, $range))->assertInertia(fn (Assert $page) => $page
        ->where('foreignOnly', false)
        ->where('rows.meta.total', 3));
    $this->actingAs($user)->get(policeUrl($property, $range.'&foreign=1'))->assertInertia(fn (Assert $page) => $page
        ->where('foreignOnly', true)
        ->where('rows.meta.total', 1)
        ->where('rows.data.0.key', $unit->id.':'.$abroad->id)
        ->where('rows.data.0.nationality', 'United Kingdom'));

    $filtered = ['from' => '2026-10-01', 'to' => '2026-10-31', 'foreign' => '1'];
    expect(registerPdfCells($this, $user, $property, 'police-report', $filtered))->toHaveCount(1)
        ->and(registerCsv($this, $user, $property, 'police-report', $filtered))->toHaveCount(2)
        ->and(registerCsv($this, $user, $property, 'police-report', registerRange()))->toHaveCount(4);

    $this->actingAs($user)->post("/p/{$property->code}/reports/police-report/layout/preview", [
        ...$filtered,
        'orientation' => 'landscape',
        'columns' => array_map(fn ($column): array => ['key' => $column->key, 'visible' => true], ReportKey::PoliceReport->columns()),
    ])->assertOk();
    expect($local->id)->not->toBe($abroad->id);
});

test('the foreign filter is only accepted by the police report', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'MY');
    registerGuest($unit, ['nationality_code' => 'MY'], primary: true);

    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31&foreign=1'))->assertOk();
    $this->actingAs($user)->get(policeUrl($property, '?from=2026-10-01&to=2026-10-31&foreign=maybe'))->assertSessionHasErrors('foreign');
    $this->actingAs($user)->get("/p/{$property->code}/reports/arrivals?from=2026-10-01&to=2026-10-31&foreign=maybe")->assertOk();

    expect(ReportKey::PoliceReport->hasForeignFilter())->toBeTrue()
        ->and(ReportKey::NonNationalGuests->hasForeignFilter())->toBeFalse();
});

test('the police report shows the details of the guest and the times in the property time zone', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG', [
        'status' => UnitStatus::CheckedOut,
        'checked_in_at' => '2026-10-09 06:30:00',
        'checked_out_at' => '2026-10-11 03:15:00',
    ]);
    registerGuest($unit, [
        'full_name' => 'Tan Mei Ling',
        'nationality_code' => 'SG',
        'gender' => 'female',
        'date_of_birth' => '1990-03-04',
        'phone' => '+6591234567',
        'address_line1' => '12 Orchard Road',
        'city' => 'Singapore',
        'postal_code' => '238888',
        'country_code' => 'SG',
    ], primary: true);

    $this->actingAs($user)->get(policeUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.guest', 'Tan Mei Ling')
        ->where('rows.data.0.gender', 'Female')
        ->where('rows.data.0.date_of_birth', '1990-03-04')
        ->where('rows.data.0.address', '12 Orchard Road, 238888 Singapore, Singapore')
        ->where('rows.data.0.checked_in_at', '9 Oct 2026 14:30')
        ->where('rows.data.0.checked_out_at', '11 Oct 2026 11:15'));
});

test('the ID is the latest document with a passport before any other type', function () {
    [$manager, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $this->actingAs($manager);
    setPermissionsTeamId($property->id);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    $guest = registerGuest($unit, ['nationality_code' => 'SG'], primary: true);
    registerDocument($guest, 'passport', 'OLD11111', '2026-01-01 00:00:00');
    registerDocument($guest, 'passport', 'NEW22222', '2026-06-01 00:00:00');
    registerDocument($guest, 'other', 'ZZZ99999', '2026-09-01 00:00:00');
    $bare = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerGuest($bare, ['nationality_code' => 'SG'], primary: true);
    $mykad = registerGuest($bare, ['nationality_code' => 'SG']);
    registerDocument($mykad, 'mykad', '900101141234', '2026-02-01 00:00:00');
    registerDocument($mykad, 'other', 'LATER5555', '2026-03-01 00:00:00');
    $filters = new ReportFilters('2026-10-01', '2026-10-31');

    $police = collect(app(PoliceReport::class)->rows($property, $filters))->keyBy('key');
    $nonNational = collect(app(NonNationalGuestsReport::class)->rows($property, $filters))->keyBy('key');

    expect($police[$unit->id.':'.$guest->id]->identity)->toBe('Passport · NEW22222')
        ->and($nonNational[$unit->id]->identity)->toBe('Passport · NEW22222')
        ->and($police[$bare->id.':'.$mykad->id]->identity)->toBe('Other · LATER5555')
        ->and($nonNational[$bare->id]->identity)->toBe('');
});

test('a user without guests.view-documents sees masked numbers on the page, in the PDF and in the spreadsheet', function () {
    [$finance, $property] = reportSetup(DefaultRoleEnum::Finance, ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');
    $range = '?from=2026-10-01&to=2026-10-31';

    foreach (['police' => policeUrl($property, $range), 'non-national' => nonNationalUrl($property, $range)] as $url) {
        $this->actingAs($finance)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('rows.data.0.identity', 'Passport · •••• 4567'));
    }

    foreach (['police-report', 'non-national-guests'] as $slug) {
        $pdf = registerPdfCells($this, $finance, $property, $slug, registerRange());
        $csv = registerCsv($this, $finance, $property, $slug, registerRange());

        expect($pdf[0]['identity'])->toBe('Passport · •••• 4567')
            ->and(collect($csv)->flatten()->contains('Passport · •••• 4567'))->toBeTrue()
            ->and(json_encode($csv))->not->toContain('A1234567');
    }

    expect(Activity::query()->where('event', 'report.exported_identity_numbers')->count())->toBe(0);
});

test('a user with guests.view-documents sees full numbers and each full export is logged', function () {
    [$manager, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');
    $range = '?from=2026-10-01&to=2026-10-31';

    $this->actingAs($manager)->get(policeUrl($property, $range))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.identity', 'Passport · A1234567'));
    $this->actingAs($manager)->get(nonNationalUrl($property, $range))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.identity', 'Passport · A1234567'));
    expect(Activity::query()->where('event', 'report.exported_identity_numbers')->count())->toBe(0);

    expect(registerPdfCells($this, $manager, $property, 'police-report', registerRange())[0]['identity'])->toBe('Passport · A1234567');
    registerCsv($this, $manager, $property, 'non-national-guests', registerRange());

    $entries = Activity::query()->where('event', 'report.exported_identity_numbers')->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->causer_id)->toBe($manager->id)
        ->and($entries[0]->subject_id)->toBe($property->id)
        ->and($entries[0]->properties->all())->toBe([
            'property_id' => $property->id,
            'report_key' => 'police-report',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'rows' => 1,
            'format' => 'pdf',
        ])
        ->and($entries[1]->properties['report_key'])->toBe('non-national-guests')
        ->and($entries[1]->properties['format'])->toBe('csv')
        ->and(json_encode($entries->pluck('properties')))->not->toContain('A1234567');
});

test('an export without any ID number or with the ID column hidden is not logged', function () {
    [$manager, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerGuest($unit, ['nationality_code' => 'SG'], primary: true);

    registerCsv($this, $manager, $property, 'police-report', registerRange());
    expect(Activity::query()->where('event', 'report.exported_identity_numbers')->count())->toBe(0);

    registerDocument($unit->guests->first()->guest, 'passport', 'A1234567');
    $columns = array_map(fn ($column): array => ['key' => $column->key, 'visible' => ! in_array($column->key, ['identity', 'id_number'], true)], ReportKey::PoliceReport->columns());
    $this->actingAs($manager)->put("/p/{$property->code}/reports/police-report/layout", ['orientation' => 'landscape', 'columns' => $columns])->assertRedirect();
    registerCsv($this, $manager, $property, 'police-report', registerRange());
    registerPdfCells($this, $manager, $property, 'police-report', registerRange());

    expect(Activity::query()->where('event', 'report.exported_identity_numbers')->count())->toBe(0);
});

test('the layout preview PDF with full numbers is logged too', function () {
    [$manager, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');

    $this->actingAs($manager)->post("/p/{$property->code}/reports/police-report/layout/preview", [
        ...registerRange(),
        'orientation' => 'landscape',
        'columns' => array_map(fn ($column): array => ['key' => $column->key, 'visible' => true], ReportKey::PoliceReport->columns()),
    ])->assertOk();

    expect(Activity::query()->where('event', 'report.exported_identity_numbers')->count())->toBe(1);
});

test('the exports use country names and a selected-row export holds only the selected rows', function () {
    [$manager, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $first = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    $firstGuest = registerGuest($first, ['nationality_code' => 'SG', 'country_code' => 'SG', 'address_line1' => '1 Road'], primary: true);
    $second = registerUnit($property, '2026-10-09', '2026-10-12', 'JP');
    $secondGuest = registerGuest($second, ['nationality_code' => 'JP'], primary: true);
    registerDocument($firstGuest, 'passport', 'A1234567');
    registerDocument($secondGuest, 'passport', 'B7654321');

    $police = registerCsv($this, $manager, $property, 'police-report', registerRange([$first->id.':'.$firstGuest->id]));
    $nonNational = registerCsv($this, $manager, $property, 'non-national-guests', registerRange([(string) $second->id]));
    $pdf = registerPdfCells($this, $manager, $property, 'police-report', registerRange());

    expect($police)->toHaveCount(2)
        ->and($police[1])->toContain('Singapore', 'Passport · A1234567', '1 Road, Singapore')
        ->and(json_encode($police))->not->toContain('B7654321')
        ->and($nonNational)->toHaveCount(3)
        ->and($nonNational[1])->toContain('Japan')
        ->and(json_encode($nonNational))->not->toContain('SG')
        ->and(collect($pdf)->pluck('nationality')->sort()->values()->all())->toBe(['Japan', 'Singapore']);

    $entries = Activity::query()->where('event', 'report.exported_identity_numbers')->orderBy('id')->get();
    expect($entries->pluck('properties.rows')->all())->toBe([1, 1, 2]);
});

test('the report only returns the requested row keys and is in the registry', function () {
    [, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    $guest = registerGuest($unit, ['nationality_code' => 'SG'], primary: true);
    registerGuest($unit, ['nationality_code' => 'SG']);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    $filters = new ReportFilters('2026-10-01', '2026-10-31');

    expect(array_map(fn (PoliceReportRow $row): string => $row->key, app(PoliceReport::class)->rows($property, $filters, [$unit->id.':'.$guest->id])))->toBe([$unit->id.':'.$guest->id])
        ->and(array_map(fn (NonNationalGuestRow $row): int => $row->key, app(NonNationalGuestsReport::class)->rows($property, $filters, [$unit->id])))->toBe([$unit->id])
        ->and(ReportKey::PoliceReport->report())->toBeInstanceOf(PoliceReport::class)
        ->and(ReportKey::NonNationalGuests->maxRangeDays())->toBe(31)
        ->and(ReportKey::PoliceReport->maxRangeDays())->toBe(31)
        ->and(array_map(fn ($column): string => $column->key, ReportKey::NonNationalGuests->columns()))->toBe(['reservation', 'guest', 'nationality', 'identity', 'id_type', 'id_number', 'room', 'arrival', 'arrival_time', 'departure', 'departure_time', 'nights', 'guests', 'adults', 'children', 'tourism_tax'])
        ->and(array_map(fn ($column): string => $column->key, ReportKey::PoliceReport->columns()))->toBe(['guest', 'reservation', 'room', 'nationality', 'gender', 'date_of_birth', 'identity', 'id_type', 'id_number', 'address', 'phone', 'arrival', 'arrival_time', 'checked_in_at', 'checked_in_date', 'checked_in_time', 'departure', 'departure_time', 'checked_out_at', 'checked_out_date', 'checked_out_time', 'status']);
});

test('a range longer than 31 days is refused', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);

    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($user)->get(policeUrl($property, '?from=2026-10-01&to=2026-11-01'))->assertSessionHasErrors('to');
    $this->actingAs($user)->get(policeUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertOk();
});

test('the reports are listed in the sidebar and read in a fixed number of queries', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $build = function (int $units) use ($property): void {
        $tax = foreignTax($property);

        for ($i = 0; $i < $units; $i++) {
            $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
            registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'P'.fake()->numerify('#######'));
            registerGuest($unit, ['nationality_code' => 'MY']);
            registerTourismTax($unit, $tax, '5.00', '2026-10-10');
        }
    };
    $count = function () use ($user, $property): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertOk();
        $this->actingAs($user)->get(policeUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $build(2);
    $count();
    $small = $count();
    $build(6);

    expect($count())->toBe($small);

    $this->actingAs($user)->get(policeUrl($property))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.contexts.2.groups', fn ($groups) => collect(collect($groups)->firstWhere('label', 'Front desk')['items'])->pluck('title')->contains('Police report')
            && collect(collect($groups)->firstWhere('label', 'Revenue')['items'])->pluck('title')->contains('Non-national guests')));
});

test('the non-national guests split columns, time columns and totals', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $first = registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['adults' => 2, 'children' => 1, 'arrival_time' => '14:00:00', 'departure_time' => '12:00:00']);
    registerUnit($property, '2026-10-09', '2026-10-12', 'SG', ['adults' => 1, 'children' => 2]);
    registerDocument($first->reservation->booker, 'passport', 'A1234567');
    $rows = app(NonNationalGuestsReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-31'));
    $row = collect($rows)->firstWhere('key', $first->id);

    expect($row->cells())->toMatchArray(['id_type' => 'Passport', 'guests' => '2 / 1', 'adults' => '2', 'children' => '1', 'arrival_time' => '14:00', 'departure_time' => '12:00'])
        ->and($row->values())->toMatchArray(['id_type' => 'Passport', 'adults' => 2, 'children' => 1])
        ->and(ReportKey::NonNationalGuests->totals($rows)->figures)->toMatchArray(['adults' => 3, 'children' => 3, 'guests' => null, 'identity' => null, 'id_number' => null]);

    $defaults = collect(ReportKey::NonNationalGuests->columns())->mapWithKeys(fn ($column): array => [$column->key => $column->defaultVisible]);

    expect($defaults->only(['id_type', 'id_number', 'arrival_time', 'departure_time', 'adults', 'children'])->contains(true))->toBeFalse()
        ->and($defaults['identity'])->toBeTrue();

    $this->actingAs($user)->get(nonNationalUrl($property, '?from=2026-10-01&to=2026-10-31'))->assertInertia(fn (Assert $page) => $page
        ->where('totals.figures.adults', 3)
        ->where('totals.figures.children', 3));
});

test('the police report split columns hold the ID parts and the dates and times in the property time zone', function () {
    [$user, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG', [
        'status' => UnitStatus::CheckedOut,
        'checked_in_at' => '2026-10-09 06:30:00',
        'checked_out_at' => '2026-10-11 16:15:00',
        'arrival_time' => '14:00:00',
        'departure_time' => '12:00:00',
    ]);
    registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');
    $bare = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');

    $rows = collect(app(PoliceReport::class)->rows($property, new ReportFilters('2026-10-01', '2026-10-31')))->keyBy(fn (PoliceReportRow $row): int => (int) explode(':', $row->key)[0]);

    expect($rows[$unit->id]->cells())->toMatchArray([
        'id_type' => 'Passport', 'arrival_time' => '14:00', 'departure_time' => '12:00',
        'checked_in_at' => '9 Oct 2026 14:30', 'checked_in_date' => '9 Oct 2026', 'checked_in_time' => '14:30',
        'checked_out_at' => '12 Oct 2026 00:15', 'checked_out_date' => '12 Oct 2026', 'checked_out_time' => '00:15',
    ])
        ->and($rows[$unit->id]->values())->toMatchArray(['id_type' => 'Passport'])
        ->and($rows[$bare->id]->cells())->toMatchArray(['id_type' => '', 'id_number' => '']);

    $defaults = collect(ReportKey::PoliceReport->columns())->mapWithKeys(fn ($column): array => [$column->key => $column->defaultVisible]);

    expect($defaults->only(['id_type', 'id_number', 'arrival_time', 'departure_time', 'checked_in_date', 'checked_in_time', 'checked_out_date', 'checked_out_time'])->contains(true))->toBeFalse()
        ->and($defaults['identity'])->toBeTrue();
});

test('the ID type and number are split, and the number is masked for a user without guests.view-documents', function () {
    [$finance, $property] = reportSetup(DefaultRoleEnum::Finance, ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');
    $range = '?from=2026-10-01&to=2026-10-31';

    foreach ([policeUrl($property, $range), nonNationalUrl($property, $range)] as $url) {
        $this->actingAs($finance)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('rows.data.0.id_type', 'Passport')
            ->where('rows.data.0.id_number', '•••• 4567'));
    }

    [$manager, $managerProperty] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $managerUnit = registerUnit($managerProperty, '2026-10-09', '2026-10-12', 'SG');
    registerDocument(registerGuest($managerUnit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');

    $this->actingAs($manager)->get(policeUrl($managerProperty, $range))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data.0.id_number', 'A1234567'));
});

test('the identity export is logged when only the ID number column is visible and not when both are hidden', function () {
    [$manager, $property] = reportSetup(settings: ['time_zone' => 'Asia/Kuala_Lumpur']);
    $unit = registerUnit($property, '2026-10-09', '2026-10-12', 'SG');
    registerDocument(registerGuest($unit, ['nationality_code' => 'SG'], primary: true), 'passport', 'A1234567');
    $layout = fn (array $visible): array => ['orientation' => 'landscape', 'columns' => array_map(fn ($column): array => ['key' => $column->key, 'visible' => in_array($column->key, $visible, true)], ReportKey::PoliceReport->columns())];

    $this->actingAs($manager)->put("/p/{$property->code}/reports/police-report/layout", $layout(['guest', 'id_number']))->assertRedirect();
    registerCsv($this, $manager, $property, 'police-report', registerRange());

    $entries = Activity::query()->where('event', 'report.exported_identity_numbers')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->properties['report_key'])->toBe('police-report')
        ->and(json_encode($entries->pluck('properties')))->not->toContain('A1234567');

    $this->actingAs($manager)->put("/p/{$property->code}/reports/police-report/layout", $layout(['guest', 'id_type']))->assertRedirect();
    registerCsv($this, $manager, $property, 'police-report', registerRange());
    registerPdfCells($this, $manager, $property, 'police-report', registerRange());

    expect(Activity::query()->where('event', 'report.exported_identity_numbers')->count())->toBe(1);
});
