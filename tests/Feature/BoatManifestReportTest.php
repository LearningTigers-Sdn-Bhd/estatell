<?php

use App\Data\Reports\BoatManifestRow;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\BusinessDate;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitBoatTrip;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use App\Models\User;
use App\Navigation\ReportsNavigation;
use App\Services\Boats\BoatSeats;
use App\Services\Reports\BoatManifestReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

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
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

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

function manifestUrl(Property $property, string $query = ''): string
{
    return "/p/{$property->code}/reports/boat-manifest".$query;
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
    View::composer('pdf.report', function ($view) use ($captured): void {
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

test('the page needs reports.view and is scoped to the property', function () {
    [$front, $frontProperty] = manifestSetup(DefaultRoleEnum::FrontDesk);
    [$manager, $own] = manifestSetup();
    [, $other] = manifestSetup();
    $departure = manifestDeparture($own, BoatDirection::In, '09:00:00', '09:45:00');
    $unit = manifestUnit($own);
    manifestTrip($own, $unit, BoatDirection::In, $departure);

    $this->actingAs($front)->get(manifestUrl($frontProperty, '?date=2026-10-20'))->assertForbidden();
    $this->actingAs($manager)->get(manifestUrl($other, '?date=2026-10-20'))->assertNotFound();
    $this->actingAs($manager)->get(manifestUrl($own, '?date=2026-10-20'))->assertInertia(fn (Assert $page) => $page
        ->component('reports/boat-manifest')
        ->has('rows.data', 1)
        ->where('rows.data.0.key', UnitBoatTrip::query()->where('reservation_unit_id', $unit->id)->value('id')));
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

test('rows follow the departure time, and charter and own boat trips come last in time order', function () {
    [, $property] = manifestSetup();
    $late = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $early = manifestDeparture($property, BoatDirection::In, '07:30:00', '08:15:00');

    manifestTrip($property, manifestUnit($property, ['arrival_time' => '11:30:00']), BoatDirection::In, null, BoatMode::Own);
    manifestTrip($property, manifestUnit($property, ['arrival_time' => '10:30:00']), BoatDirection::In, null, BoatMode::Charter);
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $late);
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $early);

    $departures = array_map(fn ($row) => $row->cells()['departure'], manifestRows($property, BoatDirection::In));

    expect($departures)->toBe([
        '07:30 Kuala Besut jetty → 08:15',
        '09:00 Kuala Besut jetty → 09:45',
        'Charter 10:30',
        'Own 11:30',
    ]);
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

test('the report is hidden and 404 while the Boats feature is off', function () {
    [$owner, $property] = manifestSetup(DefaultRoleEnum::Owner, featureOn: false);
    setPermissionsTeamId($property->id);
    $titles = collect(app(ReportsNavigation::class)->groups($property, $owner))
        ->flatMap(fn ($group) => collect($group->items)->pluck('title'))
        ->all();

    expect($titles)->not->toContain('Boat manifest');

    $this->actingAs($owner)->get(manifestUrl($property, '?date=2026-10-20'))->assertNotFound();
    $this->actingAs($owner)->post(manifestUrl($property).'/pdf', ['date' => '2026-10-20'])->assertNotFound();
    $this->actingAs($owner)->post(manifestUrl($property).'/spreadsheet', ['date' => '2026-10-20', 'format' => 'csv'])->assertNotFound();
    $this->actingAs($owner)->put("/p/{$property->code}/reports/boat-manifest/layout", [])->assertNotFound();

    Feature::for($property)->activate(Boats::class);
    $owner = User::find($owner->id);

    $titles = collect(app(ReportsNavigation::class)->groups($property, $owner))
        ->flatMap(fn ($group) => collect($group->items)->pluck('title'))
        ->all();

    expect($titles)->toContain('Boat manifest');
    $this->actingAs($owner)->get(manifestUrl($property, '?date=2026-10-20'))->assertOk();
});

test('the direction defaults to Boat in and Boat out is read from the URL', function () {
    [$manager, $property] = manifestSetup();

    $this->actingAs($manager)->get(manifestUrl($property, '?date=2026-10-20'))
        ->assertInertia(fn (Assert $page) => $page->where('direction', 'in'));
    $this->actingAs($manager)->get(manifestUrl($property, '?date=2026-10-20&direction=out'))
        ->assertInertia(fn (Assert $page) => $page->where('direction', 'out'));
});

test('the PDF has one section per departure and leaves the charter and own boat trips out by default', function () {
    [$manager, $property] = manifestSetup();
    $first = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    $second = manifestDeparture($property, BoatDirection::In, '10:00:00', '10:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $first);
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $second);
    manifestTrip($property, manifestUnit($property), BoatDirection::In, null, BoatMode::Charter);
    $captured = manifestPdfView();

    $response = $this->actingAs($manager)->post(manifestUrl($property).'/pdf', ['date' => '2026-10-20', 'direction' => 'in']);

    $response->assertOk();
    expect(collect($captured->data['sections'])->pluck('title')->all())
        ->toBe(['09:00 Kuala Besut jetty → 09:45', '10:00 Kuala Besut jetty → 10:45']);
});

test('selected charter and own boat rows print in a last section when selected', function () {
    [$manager, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    $charter = manifestTrip($property, manifestUnit($property), BoatDirection::In, null, BoatMode::Charter);
    $captured = manifestPdfView();

    $this->actingAs($manager)->post(manifestUrl($property).'/pdf', [
        'date' => '2026-10-20',
        'direction' => 'in',
        'rowKeys' => [$charter->id],
    ])->assertOk();

    expect($captured->data['sections'])->toHaveCount(1)
        ->and($captured->data['sections'][0]['title'])->toBe(__('Charter and own boat (front desk only)'))
        ->and($captured->data['sections'][0]['rows'])->toHaveCount(1);
});

test('the spreadsheet is flat and keeps the charter and own boat trips', function () {
    [$manager, $property] = manifestSetup();
    $departure = manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');
    manifestTrip($property, manifestUnit($property), BoatDirection::In, $departure);
    manifestTrip($property, manifestUnit($property, ['arrival_time' => '10:30:00']), BoatDirection::In, null, BoatMode::Charter);

    $response = $this->actingAs($manager)->post(manifestUrl($property).'/spreadsheet', [
        'date' => '2026-10-20',
        'direction' => 'in',
        'format' => 'csv',
    ])->assertOk();

    $content = file_get_contents($response->getFile()->getPathname());

    expect($content)->toContain('Charter 10:30')
        ->and($content)->not->toContain('front desk only');
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

test('an empty date says there is no boat trip, and no departures says so with a settings link for managers of settings', function () {
    [$owner, $property] = manifestSetup(DefaultRoleEnum::Owner);

    $this->actingAs($owner)->get(manifestUrl($property, '?date=2026-10-20'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('emptyTitle', 'No departures yet')
            ->where('settingsUrl', "/p/{$property->code}/settings/boats/departures"));

    manifestDeparture($property, BoatDirection::In, '09:00:00', '09:45:00');

    $this->actingAs($owner)->get(manifestUrl($property, '?date=2026-10-20'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('emptyTitle', 'No boat in trips on 20 Oct.')
            ->where('settingsUrl', null));
});
