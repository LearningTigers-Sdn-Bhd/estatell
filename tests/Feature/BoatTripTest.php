<?php

use App\Actions\Boats\BoatTripResult;
use App\Actions\Boats\ClearBoatTrip;
use App\Actions\Boats\DeleteBoatDeparture;
use App\Actions\Boats\SetBoatTrip;
use App\Actions\Boats\UpdateBoatDeparture;
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
use App\Services\Boats\BoatSeats;
use App\Services\Reservations\ReservationActivityLog;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner, a live property with the Boats feature on, a business date of 10 Oct 2026, a reservation and one
 * confirmed unit that arrives 20 Oct and leaves 23 Oct. Departures: a Boat in at 09:00 (arrives 09:45) and a Boat out
 * at 12:00 (arrives at the jetty 12:45).
 *
 * @return array{owner: User, property: Property, reservation: Reservation, unit: ReservationUnit, in: BoatDeparture, out: BoatDeparture}
 */
function boatTripFixture(bool $featureOn = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => Currency::Myr]);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-10']);

    if ($featureOn) {
        Feature::for($property)->activate(Boats::class);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = boatTripUnit($reservation, ['status' => UnitStatus::Confirmed]);

    return [
        'owner' => $owner,
        'property' => $property,
        'reservation' => $reservation,
        'unit' => $unit,
        'in' => BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20]),
        'out' => BoatDeparture::factory()->for($property)->out()->create(['leaves_at' => '12:00', 'arrives_at' => '12:45', 'pickup_point' => 'Resort jetty', 'seats' => 20]),
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function boatTripUnit(Reservation $reservation, array $attributes = []): ReservationUnit
{
    return ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $reservation->property_id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
        'adults' => 2,
        'children' => 1,
        ...$attributes,
        ...boatTripStampsFor($attributes['status'] ?? UnitStatus::Confirmed),
    ]);
}

/** The checked-in and checked-out timestamps the stay check constraint requires. */
function boatTripStampsFor(UnitStatus $status): array
{
    return match ($status) {
        UnitStatus::CheckedIn => ['checked_in_at' => now()],
        UnitStatus::CheckedOut => ['checked_in_at' => now(), 'checked_out_at' => now()],
        default => [],
    };
}

/** Status is not fillable, so tests set it with forceFill, with the timestamps it needs. */
function boatTripSetStatus(ReservationUnit $unit, UnitStatus $status): ReservationUnit
{
    $unit->forceFill(['status' => $status, ...boatTripStampsFor($status)])->save();

    return $unit->refresh();
}

function boatTripSet(array $fixture, ReservationUnit $unit, BoatDirection $direction, BoatMode $mode, ?int $departureId = null, ?string $time = null, ?string $note = null): BoatTripResult
{
    return app(SetBoatTrip::class)($fixture['owner'], $unit, new BoatTripData($direction, $mode, $departureId, $time, $note));
}

test('a scheduled boat in sets the trip, the unit arrival time and logs the trip', function () {
    $fixture = boatTripFixture();

    $result = boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    expect($result->isRefused())->toBeFalse();
    $trip = UnitBoatTrip::query()->sole();
    expect($trip->boat_departure_id)->toBe($fixture['in']->id)
        ->and($trip->mode)->toBe(BoatMode::Scheduled)
        ->and($fixture['unit']->refresh()->arrival_time)->toBe('09:45:00');

    $activity = Activity::query()->where('event', 'reservation.boat_trip_set')->sole();
    expect($activity->properties->get('boat_time'))->toBe('09:00')
        ->and($activity->properties->get('pickup_point'))->toBe('Kuala Besut jetty');
});

test('a scheduled boat out sets the departure time from the departure, not the arrival', function () {
    $fixture = boatTripFixture();

    boatTripSet($fixture, $fixture['unit'], BoatDirection::Out, BoatMode::Scheduled, $fixture['out']->id);

    expect($fixture['unit']->refresh()->departure_time)->toBe('12:00:00')
        ->and($fixture['unit']->arrival_time)->toBe('15:00:00');
});

test('a charter trip takes the typed time and keeps no departure', function () {
    $fixture = boatTripFixture();

    $result = boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Charter, null, '10:30', 'Private transfer');

    expect($result->isRefused())->toBeFalse();
    expect(UnitBoatTrip::query()->sole())
        ->mode->toBe(BoatMode::Charter)
        ->boat_departure_id->toBeNull()
        ->note->toBe('Private transfer');
    expect($fixture['unit']->refresh()->arrival_time)->toBe('10:30:00');
});

test('a charter or own trip without a time is refused and writes nothing', function () {
    $fixture = boatTripFixture();

    $result = boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Own, null, null);

    expect($result->refusal)->toBe('time_required')
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.boat_trip_set')->count())->toBe(0);
});

test('the set refusals write nothing and log nothing', function (callable $arrange, string $expected) {
    $fixture = boatTripFixture();
    $departureId = $arrange($fixture) ?? $fixture['in']->id;

    $result = boatTripSet($fixture, $fixture['unit']->refresh(), BoatDirection::In, BoatMode::Scheduled, $departureId);

    expect($result->refusal)->toBe($expected)
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.boat_trip_set')->count())->toBe(0)
        ->and($fixture['unit']->refresh()->arrival_time)->toBe('15:00:00');
})->with([
    'the feature is off' => [function (array &$f) {
        Feature::for($f['property'])->deactivate(Boats::class);
    }, 'boats_off'],
    'boat in on a checked in unit' => [function (array &$f) {
        boatTripSetStatus($f['unit'], UnitStatus::CheckedIn);
    }, 'unit_not_open'],
    'departure of another property' => [fn (array &$f) => BoatDeparture::factory()->create(['direction' => BoatDirection::In])->id, 'departure_not_found'],
    'inactive departure' => [function (array &$f) {
        $f['in']->update(['active' => false]);
    }, 'departure_not_found'],
    'departure of the other direction' => [fn (array &$f) => $f['out']->id, 'departure_not_found'],
]);

test('boat out on a checked out unit is refused, and boat out on a checked in unit is allowed', function () {
    $fixture = boatTripFixture();

    boatTripSetStatus($fixture['unit'], UnitStatus::CheckedIn);
    expect(boatTripSet($fixture, $fixture['unit']->refresh(), BoatDirection::Out, BoatMode::Scheduled, $fixture['out']->id)->isRefused())->toBeFalse();

    boatTripSetStatus($fixture['unit'], UnitStatus::CheckedOut);
    expect(boatTripSet($fixture, $fixture['unit']->refresh(), BoatDirection::Out, BoatMode::Scheduled, $fixture['out']->id)->refusal)->toBe('unit_not_open');
});

test('replacing a trip updates its row in place, so its id stays', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);
    $firstId = UnitBoatTrip::query()->sole()->id;

    $other = BoatDeparture::factory()->for($fixture['property'])->create(['leaves_at' => '13:00', 'arrives_at' => '13:45']);
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $other->id);

    $trip = UnitBoatTrip::query()->sole();
    expect($trip->id)->toBe($firstId)
        ->and($trip->boat_departure_id)->toBe($other->id)
        ->and($fixture['unit']->refresh()->arrival_time)->toBe('13:45:00');
});

test('setting the same trip again changes nothing and logs nothing', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    expect(Activity::query()->where('event', 'reservation.boat_trip_set')->count())->toBe(1);
});

test('a full departure still takes the trip, because seats never refuse', function () {
    $fixture = boatTripFixture();
    $fixture['in']->update(['seats' => 1]);

    expect(boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id)->isRefused())->toBeFalse();
});

test('clearing a trip removes it, keeps the unit time and logs it', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    $result = app(ClearBoatTrip::class)($fixture['owner'], $fixture['unit']->refresh(), BoatDirection::In);

    expect($result->isRefused())->toBeFalse()
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and($fixture['unit']->refresh()->arrival_time)->toBe('09:45:00')
        ->and(Activity::query()->where('event', 'reservation.boat_trip_cleared')->count())->toBe(1);
});

test('clearing a direction with no trip does nothing and logs nothing', function () {
    $fixture = boatTripFixture();

    $result = app(ClearBoatTrip::class)($fixture['owner'], $fixture['unit'], BoatDirection::Out);

    expect($result->isRefused())->toBeFalse()
        ->and(Activity::query()->where('event', 'reservation.boat_trip_cleared')->count())->toBe(0);
});

test('clearing is refused when the feature is off or the unit is checked out', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    boatTripSetStatus($fixture['unit'], UnitStatus::CheckedOut);
    expect(app(ClearBoatTrip::class)($fixture['owner'], $fixture['unit']->refresh(), BoatDirection::In)->refusal)->toBe('unit_not_open');

    Feature::for($fixture['property'])->deactivate(Boats::class);
    expect(app(ClearBoatTrip::class)($fixture['owner'], $fixture['unit'], BoatDirection::In)->refusal)->toBe('boats_off')
        ->and(UnitBoatTrip::query()->count())->toBe(1);
});

test('the activity line names the direction, the boat time and the pickup point', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);
    boatTripSet($fixture, $fixture['unit'], BoatDirection::Out, BoatMode::Own, null, '11:30');

    $texts = collect(app(ReservationActivityLog::class)->paginate($fixture['reservation'])->items())->pluck('text')->all();

    expect($texts)->toContain('Boat out: Own boat 11:30.')
        ->toContain('Boat in: Scheduled boat 09:00 (Kuala Besut jetty).');
});

test('seats count every guest on a trip by date and direction, and skip cancelled units', function () {
    $fixture = boatTripFixture();
    $checkedOut = boatTripUnit($fixture['reservation'], ['status' => UnitStatus::CheckedOut, 'adults' => 1, 'children' => 0]);
    $cancelled = boatTripUnit($fixture['reservation'], ['status' => UnitStatus::Cancelled, 'adults' => 4, 'children' => 0]);
    $otherDay = boatTripUnit($fixture['reservation'], ['arrival_date' => '2026-10-21', 'adults' => 3, 'children' => 0]);

    // Trips are rows here: SetBoatTrip itself refuses a checked-out unit, while seats count every status that holds a bed.
    foreach ([$fixture['unit'], $checkedOut, $cancelled, $otherDay] as $unit) {
        UnitBoatTrip::factory()->create([
            'property_id' => $fixture['property']->id,
            'reservation_unit_id' => $unit->id,
            'boat_departure_id' => $fixture['in']->id,
            'direction' => BoatDirection::In,
        ]);
    }

    $seats = app(BoatSeats::class)->forDate(BoatDirection::In, CarbonImmutable::parse('2026-10-20'), [$fixture['in']->id, $fixture['out']->id]);

    expect($seats)->toBe([$fixture['in']->id => 4, $fixture['out']->id => 0]);
});

test('updating a departure time moves the upcoming scheduled units only, and counts their bookings', function () {
    $fixture = boatTripFixture();
    $upcoming = $fixture['unit'];
    $checkedIn = boatTripUnit($fixture['reservation'], ['status' => UnitStatus::CheckedIn, 'arrival_time' => '09:45:00']);
    $past = boatTripUnit($fixture['reservation'], ['arrival_date' => '2026-10-05', 'arrival_time' => '09:45:00']);
    boatTripSet($fixture, $upcoming, BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);
    foreach ([$checkedIn, $past] as $unit) {
        UnitBoatTrip::factory()->create([
            'property_id' => $fixture['property']->id,
            'reservation_unit_id' => $unit->id,
            'boat_departure_id' => $fixture['in']->id,
            'direction' => BoatDirection::In,
        ]);
    }

    $result = app(UpdateBoatDeparture::class)($fixture['owner'], $fixture['in'], [
        'direction' => 'in', 'leaves_at' => '09:00', 'arrives_at' => '09:50', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20, 'charge_item_id' => null, 'active' => true,
    ]);

    expect($result->isRefused())->toBeFalse()
        ->and($result->bookingsChanged)->toBe(1)
        ->and($upcoming->refresh()->arrival_time)->toBe('09:50:00')
        ->and($checkedIn->refresh()->arrival_time)->toBe('09:45:00')
        ->and($past->refresh()->arrival_time)->toBe('09:45:00')
        ->and(Activity::query()->where('event', 'boat_departure.updated')->sole()->properties->get('bookings_changed'))->toBe(1);
});

test('changing only the pick-up point moves no unit times', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    $result = app(UpdateBoatDeparture::class)($fixture['owner'], $fixture['in'], [
        'direction' => 'in', 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'New jetty', 'seats' => 20, 'charge_item_id' => null, 'active' => true,
    ]);

    expect($result->bookingsChanged)->toBe(0)
        ->and($fixture['unit']->refresh()->arrival_time)->toBe('09:45:00');
});

test('a departure cannot change its direction while a trip uses it', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);

    $result = app(UpdateBoatDeparture::class)($fixture['owner'], $fixture['in'], [
        'direction' => 'out', 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20, 'charge_item_id' => null, 'active' => true,
    ]);

    expect($result->refusal)->toBe('departure_in_use')
        ->and($fixture['in']->refresh()->direction)->toBe(BoatDirection::In);
});

test('a departure used by a trip of any status cannot be deleted, an unused one can', function () {
    $fixture = boatTripFixture();
    boatTripSet($fixture, $fixture['unit'], BoatDirection::In, BoatMode::Scheduled, $fixture['in']->id);
    boatTripSetStatus($fixture['unit'], UnitStatus::Cancelled);

    $refused = app(DeleteBoatDeparture::class)($fixture['owner'], $fixture['in']);

    expect($refused->refusal)->toBe('departure_in_use')
        ->and(BoatDeparture::query()->whereKey($fixture['in']->id)->exists())->toBeTrue();

    $result = app(DeleteBoatDeparture::class)($fixture['owner'], $fixture['out']);

    expect($result->isRefused())->toBeFalse()
        ->and(BoatDeparture::query()->whereKey($fixture['out']->id)->exists())->toBeFalse();
});

test('the trip routes set and clear a trip for staff with reservations.manage', function () {
    $fixture = boatTripFixture();
    $url = route('reservations.units.boat-trips.set', [
        'property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id, 'unit' => $fixture['unit']->id, 'direction' => 'in',
    ]);

    $this->actingAs($fixture['owner'])
        ->put($url, ['mode' => 'scheduled', 'boat_departure_id' => $fixture['in']->id])
        ->assertRedirect();

    expect(UnitBoatTrip::query()->count())->toBe(1);

    $this->actingAs($fixture['owner'])
        ->delete(route('reservations.units.boat-trips.clear', [
            'property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id, 'unit' => $fixture['unit']->id, 'direction' => 'in',
        ]))
        ->assertRedirect();

    expect(UnitBoatTrip::query()->count())->toBe(0);
});

test('the trip routes are 404 while the feature is off and refuse staff without reservations.manage', function () {
    $fixture = boatTripFixture(featureOn: false);
    $params = ['property' => $fixture['property']->code, 'reservation' => $fixture['reservation']->id, 'unit' => $fixture['unit']->id, 'direction' => 'in'];

    $this->actingAs($fixture['owner'])
        ->put(route('reservations.units.boat-trips.set', $params), ['mode' => 'scheduled', 'boat_departure_id' => $fixture['in']->id])
        ->assertNotFound();

    Feature::for($fixture['property'])->activate(Boats::class);
    $housekeeper = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create();
    $housekeeper->forceFill(['property_id' => $fixture['property']->id])->save();

    $this->actingAs($housekeeper)
        ->put(route('reservations.units.boat-trips.set', $params), ['mode' => 'scheduled', 'boat_departure_id' => $fixture['in']->id])
        ->assertForbidden();

    expect(UnitBoatTrip::query()->count())->toBe(0);
});
