<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Actions\Boats\SetBoatTrip;
use App\Data\Boats\BoatTripData;
use App\Data\Reports\BoatManifestRow;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\DefaultRoleEnum;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\BoatSailing;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReportLayout;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitBoatTrip;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Navigation\FrontDeskNavigation;
use App\Services\Boats\BoatSeats;
use App\Services\Reports\BoatManifestReport;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A manager (or the given role) of a live property on 10 Oct 2026. The Boats feature is on unless `$featureOn` is false.
 *
 * @return array{0: User, 1: Property}
 */
function manifestSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager, bool $featureOn = true): array
{
    [$user, $property] = reportSetup($role);

    if ($featureOn) {
        Feature::for($property)->activate(Boats::class);
    }

    return [$user, $property];
}

function manifestDeparture(Property $property, BoatDirection $direction, string $leavesAt, string $arrivesAt, ?int $seats = 20): BoatDeparture
{
    return BoatDeparture::factory()->for($property)->create([
        'direction' => $direction,
        'leaves_at' => $leavesAt,
        'arrives_at' => $arrivesAt,
        'pickup_point' => 'Kuala Besut jetty',
        'seats' => $seats,
    ]);
}

/**
 * A unit that stays 20 to 23 Oct with 2 adults and 1 child, booked by "Booker Name". A lead guest named `$guestName`
 * is added as the primary guest, unless it is null.
 *
 * @param  array<string, mixed>  $attributes
 */
function manifestUnit(Property $property, array $attributes = [], ?string $guestName = 'Lead Guest'): ReservationUnit
{
    $booker = Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Booker Name', 'phone' => '+60111111111']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'booker_guest_id' => $booker->id]);
    $status = $attributes['status'] ?? UnitStatus::Confirmed;

    $stamps = match ($status) {
        UnitStatus::CheckedIn => ['checked_in_at' => now()],
        UnitStatus::CheckedOut => ['checked_in_at' => now(), 'checked_out_at' => now()],
        default => [],
    };

    $unit = ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $property->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
        'adults' => 2,
        'children' => 1,
        'status' => $status,
        ...$stamps,
        ...$attributes,
    ]);

    if ($guestName !== null) {
        $guest = Guest::factory()->create(['property_id' => $property->id, 'full_name' => $guestName, 'phone' => '+60123456789']);
        UnitGuest::factory()->primary()->create(['reservation_unit_id' => $unit->id, 'guest_id' => $guest->id]);
    }

    return $unit;
}

function manifestTrip(Property $property, ReservationUnit $unit, BoatDirection $direction = BoatDirection::In, ?BoatDeparture $departure = null, BoatMode $mode = BoatMode::Scheduled): UnitBoatTrip
{
    return UnitBoatTrip::factory()->create([
        'property_id' => $property->id,
        'reservation_unit_id' => $unit->id,
        'direction' => $direction,
        'mode' => $mode,
        'boat_departure_id' => $mode === BoatMode::Scheduled ? $departure?->id : null,
        'note' => null,
    ]);
}

/**
 * The rows of the manifest for one date and direction, from the service.
 *
 * @return list<BoatManifestRow>
 */
function manifestRows(Property $property, BoatDirection $direction, string $date = '2026-10-20'): array
{
    return app(BoatManifestReport::class)->rows($property, new ReportFilters(from: $date, to: $date, direction: $direction));
}

/**
 * The Boat Trips board and its export routes: the manifest has no page of its own.
 */
function manifestUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/boats".$query;
}

/**
 * Captures the data the PDF view is rendered with.
 *
 * @return object{data: array<string, mixed>}
 */
function manifestPdfView(): object
{
    $captured = new stdClass;
    $captured->data = [];
    View::composer('pdf.boat-manifest', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    return $captured;
}

/**
 * Runs the manifest page and returns how many queries it made.
 */
function manifestQueryCount(User $user, Property $property): int
{
    Model::preventLazyLoading();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->actingAs($user)->get(manifestUrl($property, '?date=2026-10-20&direction=in'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    Model::preventLazyLoading(false);

    return $count;
}

test('the rows reach the board for reservations.view, scoped to the property, and the old manifest page is gone', function () {
    [$front, $frontProperty] = manifestSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = manifestSetup();
    [, $other] = manifestSetup();
    $departure = manifestDeparture($own, BoatDirection::In, '09:00:00', '09:45:00');
    $unit = manifestUnit($own);
    manifestTrip($own, $unit, BoatDirection::In, $departure);

    $this->actingAs($front)->get(manifestUrl($frontProperty, '?date=2026-10-20'))->assertOk();
    $this->actingAs($manager)->get(manifestUrl($other, '?date=2026-10-20'))->assertNotFound();
    $this->actingAs($manager)->get(manifestUrl($own, '?date=2026-10-20'))->assertInertia(fn (Assert $page) => $page
        ->component('boats/index')
        ->has('groups.0.rows', 1)
        ->where('groups.0.rows.0.key', UnitBoatTrip::query()->where('reservation_unit_id', $unit->id)->value('id')));

    $this->actingAs($manager)->get("/p/{$own->code}/reports/boat-manifest?date=2026-10-20")->assertNotFound();
    $this->actingAs($manager)->get("/p/{$own->code}/reports/boat-manifest")->assertNotFound();
    expect(Route::has('reports.boat-manifest'))->toBeFalse()
        ->and(Route::has('reports.boat-manifest.tick'))->toBeFalse();
});

test('Boat in matches the arrival date and Boat out matches the departure date', function () {
    [, $property] = manifestSetup();
    $inDeparture = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $outDeparture = manifestDeparture($property, BoatDirection::Out, '12:00:00', '12:45:00');

    $arriving = manifestUnit($property);
    manifestTrip($property, $arriving, BoatDirection::In, $inDeparture);

    $leaving = manifestUnit($property, ['arrival_date' => '2026-10-18', 'departure_date' => '2026-10-20']);
    manifestTrip($property, $leaving, BoatDirection::Out, $outDeparture);

    $inRows = manifestRows($property, BoatDirection::In);
    $outRows = manifestRows($property, BoatDirection::Out);

    expect($inRows)->toHaveCount(1)
        ->and($inRows[0]->reservation)->toBe($arriving->reservation->reference)
        ->and($outRows)->toHaveCount(1)
        ->and($outRows[0]->reservation)->toBe($leaving->reservation->reference);
});

test('cancelled and no-show units are left out of the manifest', function () {
    [, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');

    manifestTrip($property, manifestUnit($property, ['status' => UnitStatus::Cancelled]), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, ['status' => UnitStatus::NoShow]), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, ['status' => UnitStatus::CheckedIn]), BoatDirection::In, $departure);

    expect(manifestRows($property, BoatDirection::In))->toHaveCount(1);
});

test('rows follow the departure time, then charter trips, then own boat trips, each in time order', function () {
    [, $property] = manifestSetup();
    $late = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $early = manifestDeparture($property, BoatDirection::In, '07:30:00', '08:15:00');

    manifestTrip($property, manifestUnit($property, ['arrival_time' => '10:30:00']), BoatDirection::In, null, BoatMode::Own);
    manifestTrip($property, manifestUnit($property, ['arrival_time' => '11:30:00']), BoatDirection::In, null, BoatMode::Charter);
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $late);
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $early);

    $departures = array_map(fn ($row) => $row->cells()['departure'], manifestRows($property, BoatDirection::In));

    expect($departures)->toBe([
        '07:30 Kuala Besut jetty → 08:15',
        '09:00 Kuala Besut jetty → 09:45',
        'Charter 11:30',
        'Own 10:30',
    ]);

    $sections = app(BoatManifestReport::class)->sections(manifestRows($property, BoatDirection::In), new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In));

    expect(array_map(fn ($section): string => $section->title, array_slice($sections, 2)))->toBe(['Charter', 'Own boat'])
        ->and($sections[2]->subtotal)->toBe('1 room · 3 pax');
});

test('the lead guest is the first guest of the unit, else the booker', function () {
    [, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');

    $withGuest = manifestUnit($property, [], 'Aisyah Rahman');
    $withoutGuest = manifestUnit($property, [], null);
    manifestTrip($property, $withGuest, BoatDirection::In, $departure);
    manifestTrip($property, $withoutGuest, BoatDirection::In, $departure);

    $names = collect(manifestRows($property, BoatDirection::In))->map(fn ($row) => $row->lead_guest)->all();

    expect($names)->toContain('Aisyah Rahman', 'Booker Name');
});

test('the row shows the lead guest phone and no identity numbers', function () {
    [, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);

    $row = manifestRows($property, BoatDirection::In)[0];

    expect($row->cells())->toHaveKeys(['departure', 'unit', 'lead_guest', 'phone', 'adults', 'children', 'total', 'reservation', 'note'])
        ->and($row->cells()['phone'])->toBe('+60123456789')
        ->and(array_keys($row->cells()))->not->toContain('identity')
        ->and($row->values())->not->toHaveKey('identity_number');
});

test('each departure has a subtotal in seats, with the warning tone over its limit', function () {
    [, $property] = manifestSetup();
    $full = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00', 20);
    $open = manifestDeparture($property, BoatDirection::In, '10:00:00', '10:45:00', null);
    $over = manifestDeparture($property, BoatDirection::In, '11:00:00', '11:45:00', 5);

    foreach ([$full, $full, $open, $over] as $departure) {
        manifestTrip($property, manifestUnit($property, ['adults' => 4, 'children' => 3]), BoatDirection::In, $departure);
    }

    $sections = app(BoatManifestReport::class)->sections(manifestRows($property, BoatDirection::In), new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In));
    $subtotals = array_map(fn ($section) => [$section->subtotal, $section->tone], $sections);

    expect($subtotals)->toBe([
        ['14 / 20 seats', null],
        ['7', null],
        ['7 / 5 seats', Tone::Warning],
    ]);
});

test('a subtotal equals the seats BoatSeats counts for the departure', function () {
    [, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property, ['adults' => 4, 'children' => 3]), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, ['adults' => 4, 'children' => 3, 'status' => UnitStatus::CheckedOut]), BoatDirection::In, $departure);

    $rows = manifestRows($property, BoatDirection::In);
    $taken = array_sum(array_map(fn ($row) => $row->total, $rows));

    expect($taken)->toBe(app(BoatSeats::class)->forDate(BoatDirection::In, CarbonImmutable::parse('2026-10-20'), [$departure->id])[$departure->id]);
});

test('the total row sums adults, children and guests for the whole date and direction', function () {
    [, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property, ['adults' => 2, 'children' => 1]), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, ['adults' => 3, 'children' => 0]), BoatDirection::In, null, BoatMode::Charter);

    $rows = manifestRows($property, BoatDirection::In);

    expect(ReportKey::BoatManifest->totals($rows)->figures)
        ->toMatchArray(['adults' => 5, 'children' => 1, 'total' => 6, 'departure' => null]);
});

test('the room is the first night for Boat in, the last night for Boat out, else the room type', function () {
    [, $property] = manifestSetup();
    $inDeparture = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $outDeparture = manifestDeparture($property, BoatDirection::Out, '12:00:00', '12:45:00');
    $first = Room::factory()->for($property)->create(['number' => '101']);
    $last = Room::factory()->for($property)->create(['number' => '202']);

    $arriving = manifestUnit($property);
    UnitNight::factory()->create(['reservation_unit_id' => $arriving->id, 'date' => '2026-10-20', 'room_id' => $first->id]);
    UnitNight::factory()->create(['reservation_unit_id' => $arriving->id, 'date' => '2026-10-21', 'room_id' => $last->id]);
    manifestTrip($property, $arriving, BoatDirection::In, $inDeparture);

    $leaving = manifestUnit($property, ['arrival_date' => '2026-10-18', 'departure_date' => '2026-10-20']);
    UnitNight::factory()->create(['reservation_unit_id' => $leaving->id, 'date' => '2026-10-18', 'room_id' => $first->id]);
    UnitNight::factory()->create(['reservation_unit_id' => $leaving->id, 'date' => '2026-10-19', 'room_id' => $last->id]);
    manifestTrip($property, $leaving, BoatDirection::Out, $outDeparture);

    $unassigned = manifestUnit($property);
    manifestTrip($property, $unassigned, BoatDirection::In, $inDeparture);

    $rooms = collect(manifestRows($property, BoatDirection::In))->mapWithKeys(fn ($row) => [$row->reservation => $row->unit])->all();
    $outRoom = manifestRows($property, BoatDirection::Out, '2026-10-20')[0]->unit;

    expect($rooms[$arriving->reservation->reference])->toBe('101')
        ->and($outRoom)->toBe('202')
        ->and($rooms[$unassigned->reservation->reference])->toBe($unassigned->roomType->name);
});

test('the board, its export and the column layout are hidden and 404 while the Boats feature is off', function () {
    [$owner, $property] = manifestSetup(DefaultRoleEnum::Owner, featureOn: false);
    setPermissionsTeamId($property->id);
    $titles = collect(app(FrontDeskNavigation::class)->groups($property, $owner))
        ->flatMap(fn ($group) => collect($group->items)->pluck('title'))
        ->all();

    expect($titles)->not->toContain('Boat Trips')->not->toContain('Boat manifest');

    $this->actingAs($owner)->get(manifestUrl($property, '?date=2026-10-20'))->assertNotFound();
    $this->actingAs($owner)->post(manifestUrl($property).'/pdf', ['date' => '2026-10-20'])->assertNotFound();
    $this->actingAs($owner)->post(manifestUrl($property).'/spreadsheet', ['date' => '2026-10-20', 'format' => 'csv'])->assertNotFound();
    $this->actingAs($owner)->post("/p/{$property->code}/reports/boat-manifest/pdf", ['date' => '2026-10-20'])->assertNotFound();
    $this->actingAs($owner)->put("/p/{$property->code}/reports/boat-manifest/layout", [])->assertNotFound();

    Feature::for($property)->activate(Boats::class);
    $owner = User::find($owner->id);

    $titles = collect(app(FrontDeskNavigation::class)->groups($property, $owner))
        ->flatMap(fn ($group) => collect($group->items)->pluck('title'))
        ->all();

    expect($titles)->toContain('Boat Trips')->not->toContain('Boat manifest');
    $this->actingAs($owner)->get(manifestUrl($property, '?date=2026-10-20'))->assertOk();
});

test('the board direction defaults to Boat in and out and a direction is read from the URL', function () {
    [$manager, $property] = manifestSetup();

    $this->actingAs($manager)->get(manifestUrl($property, '?date=2026-10-20'))
        ->assertInertia(fn (Assert $page) => $page->where('direction', 'both'));
    $this->actingAs($manager)->get(manifestUrl($property, '?date=2026-10-20&direction=out'))
        ->assertInertia(fn (Assert $page) => $page->where('direction', 'out'));
});

test('the PDF is landscape, whatever the saved layout says, and bands every departure in time order', function () {
    [$manager, $property] = manifestSetup();
    $first = manifestDeparture($property, BoatDirection::Out, '08:00:00', '08:45:00');
    $empty = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $last = manifestDeparture($property, BoatDirection::Out, '14:30:00', '15:15:00');
    manifestTrip($property, manifestUnit($property, ['arrival_date' => '2026-10-17', 'departure_date' => '2026-10-20']), BoatDirection::Out, $first);
    manifestTrip($property, manifestUnit($property, ['arrival_date' => '2026-10-17', 'departure_date' => '2026-10-20']), BoatDirection::Out, $last);
    $ownBoat = manifestTrip($property, manifestUnit($property), BoatDirection::In, null, BoatMode::Own);
    $charterBoat = manifestTrip($property, manifestUnit($property), BoatDirection::In, null, BoatMode::Charter);
    $captured = manifestPdfView();
    ReportLayout::factory()->create(['property_id' => $property->id, 'report_key' => ReportKey::BoatManifest->value, 'orientation' => PageOrientation::Portrait]);

    $pdf = app(ReportPdf::class)->make($property, ReportKey::BoatManifest, new ReportFilters(from: '2026-10-20', to: '2026-10-20', bothDirections: true), app(ReportLayoutResolver::class)->resolve($property, ReportKey::BoatManifest));

    expect($pdf->output())->toStartWith('%PDF')
        ->and($pdf->getDomPDF()->getPaperOrientation())->toBe('landscape')
        ->and($captured->data['sheetTitle'])->toBe(mb_strtoupper($property->name).' MANIFEST')
        ->and($captured->data['sheetDate'])->toBe('20 Oct 2026')
        ->and(collect($captured->data['sections'])->map(fn (array $section): array => [$section['kind'], $section['heading'], count($section['rows'])])->all())
        ->toBe([
            ['out', '0800', 1],
            ['in', '0900', 0],
            ['out', '1430', 1],
            ['in', 'CHARTER', 1],
            ['in', 'OWN BOAT', 1],
            ['job', 'BOAT JOBS', 0],
        ])
        ->and($captured->data['sections'][3]['rows'][0]->key)->toBe($charterBoat->id)
        ->and($captured->data['sections'][4]['rows'][0]->key)->toBe($ownBoat->id)
        ->and($captured->data['sections'][4]['jetty'])->toBe($empty->pickup_point);
});

test('the board tabs export their own rows: scheduled boats on Boat in and Boat out, Charter, Own boat and Boat jobs on Others, an empty one as a blank block', function () {
    [$manager, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestDeparture($property, BoatDirection::In, '11:00:00', '11:45:00');
    $scheduled = manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    $charter = manifestTrip($property, manifestUnit($property), BoatDirection::In, null, BoatMode::Charter);
    $captured = manifestPdfView();

    $this->actingAs($manager)->post(manifestUrl($property).'/pdf', ['date' => '2026-10-20', 'direction' => 'both', 'boat_group' => 'other'])->assertOk();

    expect(collect($captured->data['sections'])->map(fn (array $section): array => [$section['kind'], $section['heading'], count($section['rows'])])->all())
        ->toBe([['in', 'CHARTER', 1], ['other', 'OWN BOAT', 0], ['job', 'BOAT JOBS', 0]])
        ->and($captured->data['sections'][0]['rows'][0]->key)->toBe($charter->id)
        ->and($captured->data['sections'][1]['band'])->toBe('In or out');

    $this->post(manifestUrl($property).'/pdf', ['date' => '2026-10-20', 'direction' => 'in', 'boat_group' => 'scheduled'])->assertOk();

    expect(collect($captured->data['sections'])->pluck('heading')->all())->toBe(['0900', '1100'])
        ->and($captured->data['sections'][0]['rows'][0]->key)->toBe($scheduled->id);
});

test('a printed row names its guest, agent, pax, boat, crew and tick', function () {
    [$manager, $property] = manifestSetup();
    $boat = Boat::factory()->create(['property_id' => $property->id, 'name' => 'SA10716']);
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $sailing = BoatSailing::factory()->forDeparture($departure)->create(['date' => '2026-10-20', 'boat_id' => $boat->id]);
    $sailing->crew()->attach(BoatCrewMember::factory()->for($property)->create(['name' => 'Awang'])->id, ['in_charge' => true]);
    $sailing->crew()->attach(BoatCrewMember::factory()->for($property)->create(['name' => 'Daus'])->id, ['in_charge' => false]);
    $trip = manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    $trip->forceFill(['ticked_at' => now(), 'ticked_by' => $manager->id])->save();
    $captured = manifestPdfView();

    $this->actingAs($manager)->post(manifestUrl($property).'/pdf', ['date' => '2026-10-20', 'direction' => 'in'])->assertOk();

    $row = $captured->data['sections'][0]['rows'][0];

    expect($row->lead_guest)->toBe('Lead Guest')
        ->and($row->total)->toBe(3)
        ->and($row->boat)->toBe('SA10716')
        ->and($row->crew)->toBe('Awang (in charge), Daus')
        ->and($row->ticked)->toBeTrue()
        ->and($captured->data['sections'][0]['pax'])->toBe(3);
});

test('selected rows print only their own sections, so an empty departure is left out', function () {
    [$manager, $property] = manifestSetup();
    manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $charter = manifestTrip($property, manifestUnit($property), BoatDirection::In, null, BoatMode::Charter);
    $captured = manifestPdfView();

    $this->actingAs($manager)->post(manifestUrl($property).'/pdf', [
        'date' => '2026-10-20',
        'direction' => 'in',
        'rowKeys' => [$charter->id],
    ])->assertOk();

    expect($captured->data['selectedOnly'])->toBeTrue()
        ->and(collect($captured->data['sections'])->filter(fn (array $section): bool => $section['rows'] !== [])->pluck('heading')->values()->all())->toBe(['CHARTER']);
});

test('ticking a row records who and when, unticking clears it, and repeats log nothing', function () {
    [$manager, $property] = manifestSetup();
    $trip = manifestTrip($property, manifestUnit($property), BoatDirection::In, manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00'));
    $url = route('boats.trips.set-tick', [$property->code, $trip->id]);

    $this->actingAs($manager)->put($url, ['ticked' => true])->assertRedirect();
    $this->actingAs($manager)->put($url, ['ticked' => true])->assertRedirect();

    expect($trip->fresh()->ticked_at)->not->toBeNull()
        ->and($trip->fresh()->ticked_by)->toBe($manager->id)
        ->and(Activity::query()->where('event', 'reservation.boat_trip_ticked')->count())->toBe(1);

    $this->actingAs($manager)->get(manifestUrl($property, '?date=2026-10-20&direction=in'))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.rows.0.ticked', true));

    $this->actingAs($manager)->put($url, ['ticked' => false])->assertRedirect();

    expect($trip->fresh()->ticked_at)->toBeNull()->and($trip->fresh()->ticked_by)->toBeNull();
});

test('ticking needs reservations.manage and keeps to the property', function () {
    [$manager, $property] = manifestSetup();
    $trip = manifestTrip($property, manifestUnit($property), BoatDirection::In, manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00'));
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    [$otherManager, $otherProperty] = manifestSetup();

    $this->actingAs($viewer)->put(route('boats.trips.set-tick', [$property->code, $trip->id]), ['ticked' => true])->assertForbidden();
    $this->actingAs($otherManager)->put(route('boats.trips.set-tick', [$otherProperty->code, $trip->id]), ['ticked' => true])->assertNotFound();
    $this->actingAs($manager)->put("/p/{$property->code}/reports/boat-manifest/trips/{$trip->id}/tick", ['ticked' => true])->assertNotFound();

    expect($trip->fresh()->ticked_at)->toBeNull();
});

test('the row note saves trimmed, keeps the tick, clears when blank and logs only a change', function () {
    [$manager, $property] = manifestSetup();
    $trip = manifestTrip($property, manifestUnit($property), BoatDirection::In, manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00'));
    $trip->forceFill(['ticked_at' => now(), 'ticked_by' => $manager->id])->save();
    $url = route('boats.trips.note', [$property->code, $trip->id]);
    $logged = fn (): int => Activity::query()->where('event', 'reservation.boat_trip_note_set')->count();

    $this->actingAs($manager)->put($url, ['note' => '  Late ferry '])->assertSessionHasNoErrors();
    $this->put($url, ['note' => 'Late ferry'])->assertSessionHasNoErrors();

    expect($trip->fresh()->note)->toBe('Late ferry')
        ->and($trip->fresh()->ticked_at)->not->toBeNull()
        ->and($logged())->toBe(1);

    $this->put($url, ['note' => ''])->assertSessionHasNoErrors();

    expect($trip->fresh()->note)->toBeNull()
        ->and($logged())->toBe(2);

    $this->put($url, ['note' => str_repeat('a', 256)])->assertSessionHasErrors('note');
});

test('the row note needs reservations.manage and keeps to the property', function () {
    [, $property] = manifestSetup();
    $trip = manifestTrip($property, manifestUnit($property), BoatDirection::In, manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00'));
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();
    [$otherManager, $otherProperty] = manifestSetup();

    $this->actingAs($viewer)->put(route('boats.trips.note', [$property->code, $trip->id]), ['note' => 'Late'])->assertForbidden();
    $this->actingAs($otherManager)->put(route('boats.trips.note', [$otherProperty->code, $trip->id]), ['note' => 'Late'])->assertNotFound();

    expect($trip->fresh()->note)->toBeNull();
});

test('moving the trip to another boat clears its tick, and a note change keeps it', function () {
    [$manager, $property] = manifestSetup();
    $first = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $second = manifestDeparture($property, BoatDirection::In, '12:00:00', '12:45:00');
    $unit = manifestUnit($property);
    $trip = manifestTrip($property, $unit, BoatDirection::In, $first);
    $trip->forceFill(['ticked_at' => now(), 'ticked_by' => $manager->id])->save();

    app(SetBoatTrip::class)($manager, $unit, new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $first->id, null, 'Window seat'));
    expect($trip->fresh()->ticked_at)->not->toBeNull();

    app(SetBoatTrip::class)($manager, $unit, new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $second->id, null, 'Window seat'));
    expect($trip->fresh()->ticked_at)->toBeNull();
});

test('the spreadsheet is flat, keeps the charter and own boat trips and lists Charter before Own boat', function () {
    [$manager, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, ['arrival_time' => '09:30:00']), BoatDirection::In, null, BoatMode::Own);
    manifestTrip($property, manifestUnit($property, ['arrival_time' => '10:30:00']), BoatDirection::In, null, BoatMode::Charter);

    $response = $this->actingAs($manager)->post(manifestUrl($property).'/spreadsheet', [
        'date' => '2026-10-20',
        'direction' => 'in',
        'format' => 'csv',
    ])->assertOk();

    $content = file_get_contents($response->getFile()->getPathname());

    expect($content)->toContain('Charter 10:30')
        ->and(strpos($content, 'Charter 10:30'))->toBeLessThan(strpos($content, 'Own 09:30'))
        ->and($content)->not->toContain('front desk only');
});

test('the export routes need reservations.view, not reports.view, and send the rows the board shows', function () {
    [$front, $property] = manifestSetup(DefaultRoleEnum::FrontDesk);
    $nobody = User::factory()->for($property)->withPermissions([PermissionEnum::GuestsView])->create();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);

    setPermissionsTeamId($property->id);

    expect($front->can(PermissionEnum::ReportsView->value))->toBeFalse()
        ->and($front->can(PermissionEnum::ReservationsView->value))->toBeTrue();

    $this->actingAs($front)->post(route('boats.pdf', $property->code), ['date' => '2026-10-20', 'direction' => 'in'])->assertOk();
    $this->actingAs($front)->post(route('boats.spreadsheet', $property->code), ['date' => '2026-10-20', 'direction' => 'in', 'format' => 'xlsx'])->assertOk();
    $this->actingAs($front)->post(route('boats.spreadsheet', $property->code), ['date' => '2026-10-20', 'direction' => 'in'])->assertSessionHasErrors('format');
    $this->actingAs($nobody)->post(route('boats.pdf', $property->code), ['date' => '2026-10-20'])->assertForbidden();
    $this->actingAs($nobody)->post(route('boats.spreadsheet', $property->code), ['date' => '2026-10-20', 'format' => 'csv'])->assertForbidden();
});

test('an export keeps to the ticked trips and drops the ids of another property', function () {
    [$manager, $property] = manifestSetup();
    [, $other] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $ticked = manifestTrip($property, manifestUnit($property, [], 'Ticked Guest'), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, [], 'Other Guest'), BoatDirection::In, $departure);
    $foreign = manifestTrip($other, manifestUnit($other, [], 'Foreign Guest'), BoatDirection::In, manifestDeparture($other, BoatDirection::In, '09:00:00', '09:45:00'));

    $response = $this->actingAs($manager)->post(route('boats.spreadsheet', $property->code), [
        'date' => '2026-10-20',
        'direction' => 'in',
        'format' => 'csv',
        'rowKeys' => [$ticked->id, $foreign->id],
    ])->assertOk();

    $content = file_get_contents($response->getFile()->getPathname());

    expect($content)->toContain('Ticked Guest')->not->toContain('Other Guest')->not->toContain('Foreign Guest');

    $captured = manifestPdfView();
    $this->post(route('boats.pdf', $property->code), ['date' => '2026-10-20', 'direction' => 'in', 'rowKeys' => [$foreign->id]])->assertOk();

    expect(collect($captured->data['sections'])->flatMap(fn (array $section) => $section['rows'])->all())->toBe([]);
});

test('the query count does not grow with the number of rows', function () {
    [$manager, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    manifestQueryCount($manager, $property);
    $one = manifestQueryCount($manager, $property);

    foreach (range(1, 9) as $index) {
        manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    }

    expect(manifestQueryCount($manager, $property))->toBe($one);
});

test('a unit that arrives with no boat set is listed to follow up, with its agent', function () {
    [, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $withTrip = manifestUnit($property);
    manifestTrip($property, $withTrip, BoatDirection::In, $departure);
    $missing = manifestUnit($property, [], 'Lim Pock Gin');
    $missing->reservation->forceFill(['company_id' => Company::factory()->for($property)->create(['name' => 'Good Earth'])->id])->save();
    manifestUnit($property, ['status' => UnitStatus::Cancelled], 'Cancelled Guest');

    $rows = manifestRows($property, BoatDirection::In);
    $sections = app(BoatManifestReport::class)->sections($rows, new ReportFilters(from: '2026-10-20', to: '2026-10-20', direction: BoatDirection::In));

    expect(array_map(fn (BoatManifestRow $row): array => [$row->lead_guest, $row->agent, $row->not_set], $rows))->toBe([
        ['Lead Guest', 'Direct booking', false],
        ['Lim Pock Gin', 'Good Earth', true],
    ])
        ->and($rows[1]->key)->toBe(BoatManifestRow::notSetKey($missing->id, BoatDirection::In))
        ->and(end($sections)->title)->toBe('Boat not set: follow up')
        ->and(end($sections)->tone)->toBe(Tone::Warning);
});

test('Boat in and out together lists every departure of the date in time order', function () {
    [$manager, $property] = manifestSetup();
    $out = manifestDeparture($property, BoatDirection::Out, '08:00:00', '08:45:00');
    $in = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $leaving = manifestUnit($property, ['arrival_date' => '2026-10-18', 'departure_date' => '2026-10-20'], 'Tan Danping');
    manifestTrip($property, $leaving, BoatDirection::Out, $out);
    $arriving = manifestUnit($property, [], 'Wang Jue Ying');
    manifestTrip($property, $arriving, BoatDirection::In, $in);
    manifestTrip($property, $arriving, BoatDirection::Out, $out);

    $filters = new ReportFilters(from: '2026-10-20', to: '2026-10-20', bothDirections: true);
    $rows = app(BoatManifestReport::class)->rows($property, $filters);
    $sections = app(BoatManifestReport::class)->sections($rows, $filters);

    expect(array_map(fn (BoatManifestRow $row): string => $row->direction.' '.$row->lead_guest, $rows))->toBe(['out Tan Danping', 'in Wang Jue Ying'])
        ->and(array_map(fn ($section): string => $section->title, $sections))->toBe(['Boat out · 08:00 Kuala Besut jetty → 08:45', 'Boat in · 09:00 Kuala Besut jetty → 09:45'])
        ->and($filters->query(true)['direction'])->toBe('both');

    $this->actingAs($manager)->get(manifestUrl($property, '?date=2026-10-20&direction=both'))
        ->assertInertia(fn (Assert $page) => $page->where('direction', 'both')->has('groups', 5)->where('groups.2.kind', 'charter')->where('groups.3.kind', 'own')->where('groups.4.kind', 'jobs'));
});
