<?php

use App\Actions\Boats\BoatTripResult;
use App\Actions\Boats\ClearBoatTrip;
use App\Actions\Boats\SetBoatTrip;
use App\Actions\Boats\SetBoatTrips;
use App\Actions\FrontDesk\CancelUnit;
use App\Data\Boats\BoatTripData;
use App\Enums\AgeBandPricing;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\ChargingUnit;
use App\Enums\DefaultRoleEnum;
use App\Enums\PolicyScope;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionCategory;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\BoatTripAddOn;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\RoomRevenuePolicy;
use App\Models\RoomType;
use App\Models\UnitAddOn;
use App\Models\UnitBoatTrip;
use App\Models\User;
use Database\Factories\BookingSourceFactory;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * An owner, a property with the Boats feature on and a business date of 10 Oct 2026, a Speedboat fare of 35.00 per guest
 * with a Child price of 50% (4 to 11), one reservation with a unit of 2 adults and a child of 6 for 20 to 23 Oct, a Boat
 * in departure at 09:00 and a Boat out departure at 12:00, both on the fare.
 *
 * @return array{owner: User, property: Property, fare: ChargeItem, child: PropertyAgeGroup, reservation: Reservation, unit: ReservationUnit, in: BoatDeparture, out: BoatDeparture}
 */
function fareTripSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-10']);
    Feature::for($property)->activate(Boats::class);

    $fare = fareMakeItem($property, 'Speedboat', '35.00');
    $child = PropertyAgeGroup::factory()->for($property)->create(['label' => 'Child', 'min_age' => 4, 'max_age' => 11]);
    $fare->childPrices()->create(['property_age_group_id' => $child->id, 'pricing' => AgeBandPricing::Percent, 'value' => '50.00']);

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);

    return [
        'owner' => $owner,
        'property' => $property,
        'fare' => $fare,
        'child' => $child,
        'reservation' => $reservation,
        'unit' => fareMakeUnit($reservation),
        'in' => fareMakeDeparture($property, BoatDirection::In, '09:00', '09:45', $fare),
        'out' => fareMakeDeparture($property, BoatDirection::Out, '12:00', '12:45', $fare),
    ];
}

function fareMakeItem(Property $property, string $name, string $amount, bool $active = true): ChargeItem
{
    $item = ChargeItem::factory()->for($property)->create([
        'name' => $name,
        'default_amount' => $amount,
        'charging_unit' => ChargingUnit::PerGuest,
        'active' => $active,
    ]);
    $item->transactionCode->update(['category' => TransactionCategory::Transport]);

    return $item->refresh();
}

function fareMakeDeparture(Property $property, BoatDirection $direction, string $leavesAt, string $arrivesAt, ?ChargeItem $fare): BoatDeparture
{
    return BoatDeparture::factory()->for($property)->create([
        'direction' => $direction,
        'leaves_at' => $leavesAt,
        'arrives_at' => $arrivesAt,
        'pickup_point' => 'Kuala Besut jetty',
        'seats' => 20,
        'charge_item_id' => $fare?->id,
    ]);
}

/**
 * A Confirmed unit by default: 2 adults and one child of 6 (in the Child age group). Arrives 20 Oct, leaves 23 Oct.
 *
 * @param  array<string, mixed>  $attributes
 */
function fareMakeUnit(Reservation $reservation, array $attributes = []): ReservationUnit
{
    $status = $attributes['status'] ?? UnitStatus::Confirmed;

    $unit = ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $reservation->property_id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
        'adults' => 2,
        'children' => 1,
        ...$attributes,
        ...match ($status) {
            UnitStatus::CheckedIn => ['checked_in_at' => now()],
            UnitStatus::CheckedOut => ['checked_in_at' => now(), 'checked_out_at' => now()],
            default => [],
        },
    ]);
    $unit->childAges()->create(['age' => 6]);

    return $unit;
}

function fareSetTrip(array $setup, ReservationUnit $unit, BoatDirection $direction, BoatMode $mode, ?int $departureId = null, ?string $time = null): BoatTripResult
{
    return app(SetBoatTrip::class)($setup['owner'], $unit, new BoatTripData($direction, $mode, $departureId, $time));
}

/** The fare rows of a unit, oldest first. */
function fareRows(ReservationUnit $unit): Collection
{
    return UnitAddOn::query()->where('reservation_unit_id', $unit->id)->orderBy('id')->get();
}

test('a scheduled boat in with a fare adds an adult row and a child row, both linked to the trip', function () {
    $setup = fareTripSetup();

    expect(fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id)->isRefused())->toBeFalse();

    $rows = fareRows($setup['unit']);
    $trip = UnitBoatTrip::query()->where('reservation_unit_id', $setup['unit']->id)->sole();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('property_age_group_id', null)->quantity)->toBe(2)
        ->and($rows->firstWhere('property_age_group_id', $setup['child']->id)->quantity)->toBe(1)
        ->and($rows->firstWhere('property_age_group_id', $setup['child']->id)->unit_amount)->toBe('17.50')
        ->and($rows->every(fn (UnitAddOn $row): bool => $row->service_date === null && $row->charge_item_id === $setup['fare']->id))->toBeTrue()
        ->and(BoatTripAddOn::query()->where('unit_boat_trip_id', $trip->id)->whereIn('unit_add_on_id', $rows->pluck('id'))->count())->toBe(2);
});

test('no departure fare, a charter and an own boat add no fare rows and no links', function () {
    $setup = fareTripSetup();
    $noFare = fareMakeDeparture($setup['property'], BoatDirection::In, '07:00', '07:45', null);

    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $noFare->id);
    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Charter, null, '10:30');
    fareSetTrip($setup, $setup['unit'], BoatDirection::Out, BoatMode::Own, null, '11:30');

    expect(fareRows($setup['unit']))->toHaveCount(0)
        ->and(BoatTripAddOn::query()->count())->toBe(0)
        ->and(UnitBoatTrip::query()->where('reservation_unit_id', $setup['unit']->id)->count())->toBe(2);
});

test('a package plan that includes the fare adds no fare rows, and the trip is still set', function () {
    $setup = fareTripSetup();
    RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'charge_item_id' => $setup['fare']->id]);

    expect(fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id)->isRefused())->toBeFalse();

    expect(fareRows($setup['unit']))->toHaveCount(0)
        ->and(UnitBoatTrip::query()->where('reservation_unit_id', $setup['unit']->id)->count())->toBe(1);
});

test('changing the departure replaces the unposted fare rows and links the new ones to the same trip', function () {
    $setup = fareTripSetup();
    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id);
    $oldIds = fareRows($setup['unit'])->pluck('id')->all();
    $tripId = UnitBoatTrip::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->id;

    $ferry = fareMakeItem($setup['property'], 'Ferry', '20.00');
    $later = fareMakeDeparture($setup['property'], BoatDirection::In, '13:00', '13:45', $ferry);

    expect(fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $later->id)->isRefused())->toBeFalse();

    $rows = fareRows($setup['unit']);

    expect(UnitAddOn::query()->whereIn('id', $oldIds)->exists())->toBeFalse()
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->charge_item_id)->toBe($ferry->id)
        ->and($rows->first()->quantity)->toBe(3)
        ->and(BoatTripAddOn::query()->where('unit_boat_trip_id', $tripId)->count())->toBe(1)
        ->and(UnitBoatTrip::query()->where('reservation_unit_id', $setup['unit']->id)->sole()->id)->toBe($tripId);
});

test('clearing a trip removes its unposted fare rows and links, keeps the unit time and logs it', function () {
    $setup = fareTripSetup();
    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id);

    $result = app(ClearBoatTrip::class)($setup['owner'], $setup['unit'], BoatDirection::In);

    expect($result->isRefused())->toBeFalse()
        ->and(fareRows($setup['unit']))->toHaveCount(0)
        ->and(BoatTripAddOn::query()->count())->toBe(0)
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and($setup['unit']->refresh()->arrival_time)->toBe('09:45:00')
        ->and(Activity::query()->where('event', 'reservation.boat_trip_cleared')->count())->toBe(1);
});

test('a posted fare row is kept on change, its link stays, and the save toast warns to reverse it on the folio', function () {
    $setup = fareTripSetup();
    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id);
    $posted = fareRows($setup['unit'])->first();
    $posted->forceFill(['folio_line_id' => FolioLine::factory()->create()->id])->save();

    $ferry = fareMakeItem($setup['property'], 'Ferry', '20.00');
    $later = fareMakeDeparture($setup['property'], BoatDirection::In, '13:00', '13:45', $ferry);

    $this->actingAs($setup['owner'])
        ->put(route('reservations.units.boat-trips.set', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id, 'direction' => 'in']), [
            'mode' => 'scheduled',
            'boat_departure_id' => $later->id,
        ])
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'Boat trip saved. The old fare is already posted. Reverse it on the folio if needed.',
        ]);

    $rows = fareRows($setup['unit']);

    expect(UnitAddOn::query()->whereKey($posted->id)->exists())->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and($rows->where('charge_item_id', $ferry->id)->count())->toBe(1)
        ->and(BoatTripAddOn::query()->count())->toBe(2);
});

test('a boat out set on a checked in unit gets the business date as its fare service date', function () {
    $setup = fareTripSetup();
    $checkedIn = fareMakeUnit($setup['reservation'], ['status' => UnitStatus::CheckedIn]);

    expect(fareSetTrip($setup, $checkedIn, BoatDirection::Out, BoatMode::Scheduled, $setup['out']->id)->isRefused())->toBeFalse();

    $rows = fareRows($checkedIn);

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (UnitAddOn $row): bool => $row->service_date?->toDateString() === '2026-10-10'))->toBeTrue();
});

test('a refused fare refuses the trip with fare_not_found on the departure field, and writes nothing', function () {
    $setup = fareTripSetup();
    $setup['fare']->update(['active' => false]);

    expect(fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id)->refusal)->toBe(BoatTripResult::REFUSAL_FARE_NOT_FOUND);

    $this->actingAs($setup['owner'])
        ->put(route('reservations.units.boat-trips.set', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'unit' => $setup['unit']->id, 'direction' => 'in']), [
            'mode' => 'scheduled',
            'boat_departure_id' => $setup['in']->id,
        ])
        ->assertSessionHasErrors(['boat_departure_id' => "This boat's fare is not available. Turn it on or change the departure's fare."]);

    expect(UnitBoatTrip::query()->count())->toBe(0)
        ->and(UnitAddOn::query()->count())->toBe(0)
        ->and($setup['unit']->refresh()->arrival_time)->toBe('15:00:00')
        ->and(Activity::query()->where('event', 'reservation.boat_trip_set')->count())->toBe(0);
});

test('a setting that changes nothing keeps the fare rows as they are and logs nothing', function () {
    $setup = fareTripSetup();
    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id);
    $before = fareRows($setup['unit'])->pluck('id')->all();

    expect(fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id)->isRefused())->toBeFalse();

    expect(fareRows($setup['unit'])->pluck('id')->all())->toBe($before)
        ->and(Activity::query()->where('event', 'reservation.boat_trip_set')->count())->toBe(1);
});

test('a boat out on a checked out unit is refused and adds no fare rows', function () {
    $setup = fareTripSetup();
    $checkedOut = fareMakeUnit($setup['reservation'], ['status' => UnitStatus::CheckedOut]);

    expect(fareSetTrip($setup, $checkedOut, BoatDirection::Out, BoatMode::Scheduled, $setup['out']->id)->refusal)->toBe(BoatTripResult::REFUSAL_UNIT_NOT_OPEN)
        ->and(fareRows($checkedOut))->toHaveCount(0)
        ->and(BoatTripAddOn::query()->count())->toBe(0);
});

/**
 * An owner, a property with the Boats feature and the Speedboat fare on the Boat in departure, two rooms of one room
 * type with a plan and rates for 20 to 26 Oct, and a guest.
 *
 * @return array{user: User, property: Property, roomType: RoomType, plan: RatePlan, guest: Guest, in: BoatDeparture, fare: ChargeItem}
 */
function fareBookingSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    PropertySetting::factory()->for($property)->create();
    Feature::for($property)->activate(Boats::class);

    $roomType = RoomType::factory()->for($property)->create();
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '101']);
    Room::factory()->for($property)->create(['room_type_id' => $roomType->id, 'number' => '102']);
    $plan = RatePlan::factory()->for($property)->create();
    $plan->roomTypes()->attach($roomType);

    for ($night = 0; $night < 7; $night++) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $roomType->id, 'date' => now()->setDate(2026, 10, 20 + $night)->toDateString(), 'amount' => '100.00']);
    }

    $fare = fareMakeItem($property, 'Speedboat', '35.00');

    return [
        'user' => $user,
        'property' => $property,
        'roomType' => $roomType,
        'plan' => $plan,
        'guest' => Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Tan Wei Ming', 'phone' => '+60123456789']),
        'fare' => $fare,
        'in' => fareMakeDeparture($property, BoatDirection::In, '09:00', '09:45', $fare),
    ];
}

/** @param  array<string, mixed>  $setup */
function fareBookingPost(array $setup): TestResponse
{
    return test()->actingAs($setup['user'])
        ->from(route('reservations.new', ['property' => $setup['property']->code]))
        ->post(route('reservations.store', ['property' => $setup['property']->code]), [
            'booking_source_id' => BookingSourceFactory::directFor($setup['property']->id)->id,
            'arrival_date' => '2026-10-20',
            'departure_date' => '2026-10-22',
            'guests' => [['guest_id' => $setup['guest']->id]],
            'billing_mode' => 'master_pays',
            'lines' => [['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []]],
            'boat_in' => ['mode' => 'scheduled', 'boat_departure_id' => $setup['in']->id],
        ]);
}

test('a booking with a scheduled boat in adds the fare to its unit and links it to the trip', function () {
    $setup = fareBookingSetup();

    fareBookingPost($setup)->assertSessionDoesntHaveErrors();

    $unit = ReservationUnit::query()->sole();
    $trip = UnitBoatTrip::query()->where('reservation_unit_id', $unit->id)->sole();

    expect(fareRows($unit))->toHaveCount(1)
        ->and(fareRows($unit)->first()->quantity)->toBe(2)
        ->and(BoatTripAddOn::query()->where('unit_boat_trip_id', $trip->id)->count())->toBe(1);
});

test('a booking whose boat in fare is refused is refused as a whole, and the Stay row shows it', function () {
    $setup = fareBookingSetup();
    $setup['fare']->update(['active' => false]);

    fareBookingPost($setup)->assertSessionHasErrors(['boat_in' => "This boat's fare is not available. Turn it on or change the departure's fare."]);

    expect(ReservationUnit::query()->count())->toBe(0)
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and(UnitAddOn::query()->count())->toBe(0);
});

test('set boats adds the fares of every unit it changes, and a refused fare on one unit saves nothing', function () {
    $setup = fareTripSetup();
    $second = fareMakeUnit($setup['reservation']);
    $changes = [
        $setup['unit']->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $setup['in']->id)],
        $second->id => ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $setup['in']->id)],
    ];

    expect(app(SetBoatTrips::class)($setup['owner'], $setup['reservation'], $changes)->isRefused())->toBeFalse()
        ->and(UnitAddOn::query()->count())->toBe(4)
        ->and(BoatTripAddOn::query()->count())->toBe(4);

    UnitAddOn::query()->delete();
    UnitBoatTrip::query()->delete();
    Activity::query()->delete();
    $setup['unit']->update(['arrival_time' => '15:00:00']);
    $second->update(['arrival_time' => '15:00:00']);

    $refusedFare = fareMakeItem($setup['property'], 'Ferry', '20.00', active: false);
    $refused = fareMakeDeparture($setup['property'], BoatDirection::In, '13:00', '13:45', $refusedFare);
    $changes[$second->id] = ['in' => new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $refused->id)];

    $result = app(SetBoatTrips::class)($setup['owner'], $setup['reservation'], $changes);

    expect($result->refusal)->toBe(BoatTripResult::REFUSAL_FARE_NOT_FOUND)
        ->and($result->unitId)->toBe($second->id)
        ->and(UnitAddOn::query()->count())->toBe(0)
        ->and(BoatTripAddOn::query()->count())->toBe(0)
        ->and(UnitBoatTrip::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.boat_trip_set')->count())->toBe(0);
});

test('cancelling a unit with remove add-ons removes its fare rows and their links', function () {
    $setup = fareTripSetup();
    RoomRevenuePolicy::factory()->for($setup['property'])->fixed('75.00')->forCode(SystemTransactionCode::CancellationRevenue, PolicyScope::WholeStay)->create();
    fareSetTrip($setup, $setup['unit'], BoatDirection::In, BoatMode::Scheduled, $setup['in']->id);
    expect(BoatTripAddOn::query()->count())->toBe(2);

    app(CancelUnit::class)($setup['owner'], $setup['unit'], true, 'Guest cancelled', null, true);

    expect($setup['unit']->refresh()->status)->toBe(UnitStatus::Cancelled)
        ->and(fareRows($setup['unit']))->toHaveCount(0)
        ->and(BoatTripAddOn::query()->count())->toBe(0);
});
