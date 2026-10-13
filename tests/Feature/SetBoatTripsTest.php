<?php

use App\Actions\Boats\BoatTripsResult;
use App\Actions\Boats\SetBoatTrips;
use App\Data\Boats\BoatTripData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\Currency;
use App\Enums\DefaultRoleEnum;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner, a property with the Boats feature on, a reservation with two confirmed units that arrive 20 Oct and leave 23
 * Oct, and a Boat in and a Boat out departure.
 *
 * @return array{owner: User, property: Property, reservation: Reservation, first: ReservationUnit, second: ReservationUnit, in: BoatDeparture, out: BoatDeparture}
 */
function bulkTripFixture(bool $featureOn = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-10']);

    if ($featureOn) {
        Feature::for($property)->activate(Boats::class);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);

    return [
        'owner' => $owner,
        'property' => $property,
        'reservation' => $reservation,
        'first' => bulkTripUnit($reservation),
        'second' => bulkTripUnit($reservation),
        'in' => BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20]),
        'out' => BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '12:00', 'arrives_at' => '12:45', 'pickup_point' => 'Resort jetty', 'seats' => 20]),
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function bulkTripUnit(Reservation $reservation, array $attributes = []): ReservationUnit
{
    return ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $reservation->property_id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
        'adults' => 2,
        'children' => 0,
        ...$attributes,
    ]);
}

/** Status is not fillable, so tests set it with forceFill, with the timestamps the stay check constraint needs. */
function bulkTripSetStatus(ReservationUnit $unit, UnitStatus $status): ReservationUnit
{
    $stamps = match ($status) {
        UnitStatus::CheckedIn => ['checked_in_at' => now()],
        default => [],
    };

    $unit->forceFill(['status' => $status, ...$stamps])->save();

    return $unit->refresh();
}

function bulkTripLogged(): int
{
    return Activity::query()->where('event', 'reservation.boat_trip_set')->count();
}

test('units of one reservation take their trips in one change, and each unit logs its trip', function () {
    $fixture = bulkTripFixture();

    $result = app(SetBoatTrips::class)($fixture['owner'], $fixture['reservation'], [
        $fixture['first']->id => [
            'in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id),
            'out' => new BoatTripData(BoatDirection::Out, BoatMode::Own, null, '11:30', "Guest's speedboat"),
        ],
        $fixture['second']->id => [
            'in' => new BoatTripData(BoatDirection::In, BoatMode::Charter, null, '10:30', null),
        ],
    ]);

    expect($result->isRefused())->toBeFalse()
        ->and(UnitBoatTrip::query()->count())->toBe(3)
        ->and(bulkTripLogged())->toBe(3)
        ->and($fixture['first']->refresh()->arrival_time)->toBe('09:45:00')
        ->and($fixture['first']->departure_time)->toBe('11:30:00')
        ->and($fixture['second']->refresh()->arrival_time)->toBe('10:30:00');
});

test('a refusal on a later unit saves nothing on the earlier one and names the unit', function () {
    $fixture = bulkTripFixture();
    bulkTripSetStatus($fixture['second'], UnitStatus::CheckedIn);

    $result = app(SetBoatTrips::class)($fixture['owner'], $fixture['reservation'], [
        $fixture['first']->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id)],
        $fixture['second']->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id)],
    ]);

    expect($result)->toBeInstanceOf(BoatTripsResult::class)
        ->and($result->refusal)->toBe('unit_not_open')
        ->and($result->unitId)->toBe($fixture['second']->id)
        ->and($result->unitLabel)->toContain('Not assigned')
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and(bulkTripLogged())->toBe(0)
        ->and($fixture['first']->refresh()->arrival_time)->toBe('15:00:00');
});

test('a null direction clears only that direction, and a missing direction is left alone', function () {
    $fixture = bulkTripFixture();
    app(SetBoatTrips::class)($fixture['owner'], $fixture['reservation'], [
        $fixture['first']->id => [
            'in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id),
            'out' => new BoatTripData(BoatDirection::Out, BoatMode::Scheduled, $fixture['out']->id),
        ],
    ]);

    $result = app(SetBoatTrips::class)($fixture['owner'], $fixture['reservation'], [
        $fixture['first']->id => ['out' => null],
    ]);

    expect($result->isRefused())->toBeFalse()
        ->and(UnitBoatTrip::query()->where('reservation_unit_id', $fixture['first']->id)->pluck('direction')->map(fn ($direction) => $direction->value)->all())->toBe(['in'])
        ->and(UnitBoatTrip::query()->count())->toBe(1);
});

test('a day use unit refuses the whole change with day_use', function () {
    $fixture = bulkTripFixture();
    $day = ReservationUnit::factory()->for($fixture['reservation'])->dayUse('2026-10-20')->create(['property_id' => $fixture['property']->id]);

    $result = app(SetBoatTrips::class)($fixture['owner'], $fixture['reservation'], [
        $fixture['first']->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id)],
        $day->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id)],
    ]);

    expect($result->refusal)->toBe('day_use')
        ->and($result->unitId)->toBe($day->id)
        ->and(UnitBoatTrip::query()->count())->toBe(0);
});

test('the set boats route sets several units and redirects with a toast', function () {
    $fixture = bulkTripFixture();

    $this->actingAs($fixture['owner'])
        ->put(route('reservations.boat-trips.set-many', ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id]), [
            'units' => [
                ['unit_id' => $fixture['first']->id, 'in' => ['mode' => 'scheduled', 'boat_departure_id' => $fixture['in']->id]],
                ['unit_id' => $fixture['second']->id, 'in' => ['mode' => 'charter', 'time' => '10:30'], 'out' => null],
            ],
        ])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Boat trips saved.']);

    expect(UnitBoatTrip::query()->count())->toBe(2);
});

test('the set boats route refuses a unit of another reservation or property with 422 and saves nothing', function () {
    $fixture = bulkTripFixture();
    $other = bulkTripFixture();
    $url = route('reservations.boat-trips.set-many', ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id]);

    $this->actingAs($fixture['owner'])
        ->put($url, ['units' => [['unit_id' => $fixture['first']->id, 'in' => ['mode' => 'charter', 'time' => '10:30']], ['unit_id' => $other['first']->id, 'in' => ['mode' => 'charter', 'time' => '10:30']]]])
        ->assertSessionHasErrors(['units.1.unit_id']);

    $this->actingAs($fixture['owner'])
        ->put($url, ['units' => [['unit_id' => $other['first']->id, 'in' => ['mode' => 'charter', 'time' => '10:30']]]])
        ->assertSessionHasErrors(['units.0.unit_id']);

    expect(UnitBoatTrip::query()->count())->toBe(0);
});

test('the set boats route checks the shape of a scheduled trip and needs the manage permission and the feature', function () {
    $fixture = bulkTripFixture();
    $url = route('reservations.boat-trips.set-many', ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id]);

    $this->actingAs($fixture['owner'])
        ->put($url, ['units' => [['unit_id' => $fixture['first']->id, 'in' => ['mode' => 'scheduled']]]])
        ->assertSessionHasErrors(['units.0.in.boat_departure_id']);

    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $housekeeper->forceFill(['property_id' => $fixture['property']->id])->save();

    $this->actingAs($housekeeper)
        ->put($url, ['units' => [['unit_id' => $fixture['first']->id, 'in' => ['mode' => 'charter', 'time' => '10:30']]]])
        ->assertForbidden();

    Feature::for($fixture['property'])->deactivate(Boats::class);

    $this->actingAs($fixture['owner'])
        ->put($url, ['units' => [['unit_id' => $fixture['first']->id, 'in' => ['mode' => 'charter', 'time' => '10:30']]]])
        ->assertNotFound();

    expect(UnitBoatTrip::query()->count())->toBe(0);
});

test('the reservation page gives the unit its boat trips and whether Change shows, and the Set boats list', function () {
    $fixture = bulkTripFixture();
    $checkedIn = bulkTripUnit($fixture['reservation']);
    bulkTripSetStatus($checkedIn, UnitStatus::CheckedIn);
    $day = ReservationUnit::factory()->for($fixture['reservation'])->dayUse('2026-10-20')->create(['property_id' => $fixture['property']->id]);

    app(SetBoatTrips::class)($fixture['owner'], $fixture['reservation'], [
        $fixture['first']->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id)],
    ]);
    $page = route('reservations.show', ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id, 'unit' => $fixture['first']->id]);

    $this->actingAs($fixture['owner'])
        ->get($page)
        ->assertInertia(fn (Assert $props) => $props
            ->where('hasBoats', true)
            ->where('unit.boatIn.summary', 'Scheduled boat · 09:00 Kuala Besut jetty → 09:45')
            ->where('unit.boatIn.departureAvailable', true)
            ->where('unit.canSetBoatIn', true)
            ->where('unit.canSetBoatOut', true)
            ->where('unit.boatOut', null));

    $this->actingAs($fixture['owner'])
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => inertia()->getVersion(), 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'boatSetup'])
        ->get($page)
        ->assertJsonCount(3, 'props.boatSetup')
        ->assertJsonPath('props.boatSetup.1.canSetBoatIn', true)
        ->assertJsonPath('props.boatSetup.2.canSetBoatIn', false)
        ->assertJsonPath('props.boatSetup.2.canSetBoatOut', true)
        ->assertJsonPath('props.boatSetup.0.boatIn.summary', 'Scheduled boat · 09:00 Kuala Besut jetty → 09:45')
        ->assertJsonPath('props.boatSetup.2.id', $checkedIn->id)
        ->assertJsonCount(3, 'props.boatSetup');
});

test('the reservation page sends no boat data and no Change while the feature is off', function () {
    $fixture = bulkTripFixture(featureOn: false);
    $page = route('reservations.show', ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id, 'unit' => $fixture['first']->id]);

    $this->actingAs($fixture['owner'])
        ->get($page)
        ->assertInertia(fn (Assert $props) => $props
            ->where('hasBoats', false)
            ->where('unit.boatIn', null)
            ->where('unit.canSetBoatIn', false));
});
