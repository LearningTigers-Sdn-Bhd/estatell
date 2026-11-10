<?php

use App\Actions\Boats\SetBoatTrip;
use App\Data\Boats\BoatTripData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Enums\UnitStatus;
use App\Features\Boats;
use App\Models\Boat;
use App\Models\BoatCrewMember;
use App\Models\BoatDeparture;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use App\Models\User;
use App\Services\Boats\DateSailing;
use App\Services\Boats\DateSailings;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

/**
 * An owner, a property with the Boats feature on and a business date of 10 Oct 2026, a Boat in departure at 09:00 (arrives
 * 09:45) with MV Sand Bay 1 (12 seats) and the crew Din (in charge) and Razak, and a second Boat in departure at 11:00.
 *
 * @return array{owner: User, property: Property, departure: BoatDeparture, other: BoatDeparture, boat: Boat, spare: Boat, din: BoatCrewMember, razak: BoatCrewMember, date: CarbonImmutable}
 */
function sailingSetup(): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-10']);
    Feature::for($property)->activate(Boats::class);

    $boat = Boat::factory()->for($property)->create(['name' => 'MV Sand Bay 1', 'seats' => 12]);
    $spare = Boat::factory()->for($property)->create(['name' => 'MV Sand Bay 2', 'seats' => 8]);
    $din = BoatCrewMember::factory()->for($property)->create(['name' => 'Din']);
    $razak = BoatCrewMember::factory()->for($property)->create(['name' => 'Razak']);
    $departure = BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '09:00', 'arrives_at' => '09:45', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20, 'default_boat_id' => $boat->id]);
    $departure->crew()->attach($din->id, ['in_charge' => true]);
    $departure->crew()->attach($razak->id, ['in_charge' => false]);
    $other = BoatDeparture::factory()->for($property)->create(['direction' => BoatDirection::In, 'leaves_at' => '11:00', 'arrives_at' => '11:45', 'pickup_point' => 'Kuala Besut jetty', 'seats' => 20]);

    return compact('owner', 'property', 'departure', 'other', 'boat', 'spare', 'din', 'razak') + ['date' => CarbonImmutable::parse('2026-10-20')];
}

/** A Confirmed unit arriving 20 Oct and leaving 23 Oct, with 2 adults, at 15:00 in and 11:00 out. */
function sailingUnit(Property $property, array $attributes = []): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);

    return ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $property->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'arrival_time' => '15:00:00',
        'departure_time' => '11:00:00',
        'adults' => 2,
        'children' => 0,
        'status' => UnitStatus::Confirmed,
        ...$attributes,
    ]);
}

function sailingFare(Property $property, string $amount): ChargeItem
{
    $item = ChargeItem::factory()->for($property)->create(['default_amount' => $amount, 'charging_unit' => ChargingUnit::PerGuest, 'active' => true]);
    $item->transactionCode->update(['category' => TransactionCategory::Transport]);

    return $item->refresh();
}

/** A unit with a Boat in trip on the departure, set through SetBoatTrip so the fare rows exist. */
function sailingTrip(array $setup, BoatDeparture $departure, array $unit = []): UnitBoatTrip
{
    $unit = sailingUnit($setup['property'], $unit);
    $result = app(SetBoatTrip::class)($setup['owner'], $unit, new BoatTripData(BoatDirection::In, BoatMode::Scheduled, $departure->id));
    expect($result->isRefused())->toBeFalse();

    return UnitBoatTrip::query()->where('reservation_unit_id', $unit->id)->sole();
}

function sailingMain(array $setup): DateSailing
{
    return app(DateSailings::class)->forDate($setup['property'], $setup['date'], [$setup['departure']->id])[$setup['departure']->id][0];
}
