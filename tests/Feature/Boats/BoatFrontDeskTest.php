<?php

require_once __DIR__.'/../Support/ReportSetup.php';

use App\Enums\BoatDirection;
use App\Enums\PageOrientation;
use App\Enums\ReportKey;
use App\Enums\StayType;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Boats\BoatTimeLabel;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array{0: User, 1: Property}
 */
function boatDeskSetup(bool $featureOn = true): array
{
    [$user, $property] = reportSetup();

    if ($featureOn) {
        Feature::for($property)->activate(Boats::class);
    }

    return [$user, $property];
}

/**
 * A unit that arrives and departs on 2026-10-10 with the given times.
 *
 * @param  array<string, mixed>  $attributes
 */
function boatDeskUnit(Property $property, string $arrivalTime, string $departureTime, array $attributes = []): ReservationUnit
{
    return ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->create([
        'property_id' => $property->id,
        'arrival_date' => '2026-10-08',
        'departure_date' => '2026-10-10',
        'arrival_time' => $arrivalTime,
        'departure_time' => $departureTime,
        ...$attributes,
    ]);
}

function boatDeskTrip(ReservationUnit $unit, BoatDirection $direction, ?string $mode = null): void
{
    $factory = UnitBoatTrip::factory()->state([
        'property_id' => $unit->property_id,
        'reservation_unit_id' => $unit->id,
        'direction' => $direction,
    ]);

    $factory = match ($mode) {
        'charter' => $factory->charter(),
        'own' => $factory->own(),
        default => $factory->state(['boat_departure_id' => BoatDeparture::query()->where('property_id', $unit->property_id)->where('direction', $direction)->first()?->id ?? BoatDeparture::factory()->create(['property_id' => $unit->property_id, 'direction' => $direction])->id]),
    };

    $factory->create();
}

test('the label shows the scheduled, charter and own trip times, a dash without a trip and a dash for day use', function () {
    [, $property] = boatDeskSetup();
    $labels = app(BoatTimeLabel::class);

    $scheduled = boatDeskUnit($property, '09:00:00', '10:00:00');
    boatDeskTrip($scheduled, BoatDirection::In);
    $charter = boatDeskUnit($property, '10:30:00', '11:00:00');
    boatDeskTrip($charter, BoatDirection::In, 'charter');
    $own = boatDeskUnit($property, '09:00:00', '11:30:00');
    boatDeskTrip($own, BoatDirection::Out, 'own');
    $none = boatDeskUnit($property, '09:00:00', '10:00:00');
    $dayUse = boatDeskUnit($property, '09:00:00', '10:00:00', ['stay_type' => StayType::DayUse, 'arrival_date' => '2026-10-10', 'departure_date' => '2026-10-10']);
    boatDeskTrip($dayUse, BoatDirection::In);

    $label = fn (ReservationUnit $unit, BoatDirection $direction): string => $labels->forUnit($unit->load('boatTrips'), $direction);

    expect($label($scheduled, BoatDirection::In))->toBe('09:00 boat')
        ->and($label($charter, BoatDirection::In))->toBe('Charter 10:30')
        ->and($label($own, BoatDirection::Out))->toBe('Own 11:30')
        ->and($label($own, BoatDirection::In))->toBe('—')
        ->and($label($none, BoatDirection::In))->toBe('—')
        ->and($label($dayUse, BoatDirection::In))->toBe('—');
});

test('arrivals and departures show the matching trip, in time order, with the boat column', function () {
    [$user, $property] = boatDeskSetup();
    $late = boatDeskUnit($property, '11:30:00', '14:00:00', ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    boatDeskTrip($late, BoatDirection::In, 'own');
    $early = boatDeskUnit($property, '09:00:00', '14:00:00', ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    boatDeskTrip($early, BoatDirection::In);
    $leaving = boatDeskUnit($property, '09:00:00', '12:15:00');
    boatDeskTrip($leaving, BoatDirection::Out, 'charter');
    $query = 'from=2026-10-10&to=2026-10-10';

    $this->actingAs($user)->get("/p/{$property->code}/reports/guest-list?tab=arrivals&{$query}")->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['boat']])->all() === [
            [$early->id, '09:00 boat'],
            [$late->id, 'Own 11:30'],
        ])
        ->where('layout.columns', fn ($columns) => collect($columns)->contains(fn ($column) => $column['key'] === 'boat' && $column['visible'])));

    $this->actingAs($user)->get("/p/{$property->code}/reports/guest-list?tab=departures&{$query}")->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['key'], $row['boat']])->all() === [
            [$leaving->id, 'Charter 12:15'],
        ]));
});

test('with boats off the column is out of the layout and no trip is read', function () {
    [$user, $property] = boatDeskSetup(featureOn: false);
    $unit = boatDeskUnit($property, '09:00:00', '10:00:00', ['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    boatDeskTrip($unit, BoatDirection::In);
    DB::enableQueryLog();

    $this->actingAs($user)->get("/p/{$property->code}/reports/guest-list?tab=arrivals&from=2026-10-10&to=2026-10-10")->assertInertia(fn (Assert $page) => $page
        ->where('layout.columns', fn ($columns) => ! collect($columns)->contains(fn ($column) => $column['key'] === 'boat'))
        ->where('rows.data.0.boat', null));

    expect(collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'unit_boat_trips')))->toBeFalse();
    expect(collect(ReportKey::Departures->columnsFor($property))->pluck('key')->contains('boat'))->toBeFalse();
});

test('a saved layout drops the boat column while boats are off and gets it back at the end when they are on', function () {
    [$user, $property] = boatDeskSetup(featureOn: false);
    $resolver = app(ReportLayoutResolver::class);
    $draft = fn (Property $property) => array_map(fn ($column): array => ['key' => $column->key, 'visible' => true], ReportKey::Arrivals->columnsFor($property));

    $layout = $resolver->fromDraft($property, ReportKey::Arrivals, PageOrientation::Portrait, [...$draft($property), ['key' => 'boat', 'visible' => true]]);

    expect(array_column($layout->columns, 'key'))->not->toContain('boat');

    $this->actingAs($user)->put("/p/{$property->code}/reports/arrivals/layout", ['orientation' => 'portrait', 'columns' => $draft($property)])->assertSessionHasNoErrors();

    Feature::for($property)->activate(Boats::class);

    $keys = array_column($resolver->resolve($property, ReportKey::Arrivals)->columns, 'key');

    expect(end($keys))->toBe('boat');
});

/**
 * The boat text the reservations list sends for each reservation, by reference.
 *
 * @return array<string, array{0: ?string, 1: ?string}>
 */
function boatDeskCardTexts(User $user, Property $property): array
{
    $texts = [];

    test()->actingAs($user)->get(route('reservations.index', ['property' => $property->code, 'filter' => ['stay' => '2026-10-01,2026-10-31']]))
        ->assertInertia(function (Assert $page) use (&$texts): void {
            $texts = collect($page->toArray()['props']['reservations']['data'])
                ->mapWithKeys(fn (array $row): array => [$row['reference'] => [$row['boatIn'], $row['boatOut']]])
                ->all();
        });

    return $texts;
}

test('the reservations list sends one boat text per side, Mixed for units that differ and nothing for day use or boats off', function () {
    [$user, $property] = boatDeskSetup();

    $single = boatDeskUnit($property, '09:00:00', '11:30:00', ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15']);
    boatDeskTrip($single, BoatDirection::In);
    boatDeskTrip($single, BoatDirection::Out, 'own');

    $group = Reservation::factory()->create(['property_id' => $property->id]);
    $attributes = ['reservation_id' => $group->id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15', 'arrival_time' => '09:00:00', 'departure_time' => '11:30:00'];
    $first = ReservationUnit::factory()->create(['property_id' => $property->id, ...$attributes]);
    $second = ReservationUnit::factory()->create(['property_id' => $property->id, ...$attributes]);
    boatDeskTrip($first, BoatDirection::In);
    boatDeskTrip($second, BoatDirection::In);
    boatDeskTrip($first, BoatDirection::Out, 'own');

    $none = boatDeskUnit($property, '09:00:00', '11:30:00', ['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15']);
    $dayUse = boatDeskUnit($property, '09:00:00', '10:00:00', ['stay_type' => StayType::DayUse, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-12']);

    $texts = boatDeskCardTexts($user, $property);

    expect($texts[$single->reservation->reference])->toBe(['09:00 boat', 'Own 11:30'])
        ->and($texts[$group->reference])->toBe(['09:00 boat', 'Mixed'])
        ->and($texts[$none->reservation->reference])->toBe(['—', '—'])
        ->and($texts[$dayUse->reservation->reference])->toBe([null, null]);

    Feature::for($property)->deactivate(Boats::class);
    DB::enableQueryLog();

    expect(boatDeskCardTexts($user, $property)[$single->reservation->reference])->toBe([null, null])
        ->and(collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'unit_boat_trips')))->toBeFalse();
});
