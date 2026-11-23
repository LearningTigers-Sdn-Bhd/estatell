<?php

use App\Enums\ChargingUnit;
use App\Enums\UnitStatus;
use App\Features\Transfers;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransferRoute;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * An owner, a live property with the Transfers feature on, a business date of 10 Oct 2026, a reservation and one
 * confirmed unit of 2 adults and 1 child that arrives 20 Oct and leaves 23 Oct. Routes: the airport in, with a shared
 * price of 40.00 per guest and a private price of 150.00 per stay, and the airport out with no prices.
 *
 * @return array{owner: User, property: Property, reservation: Reservation, unit: ReservationUnit, in: TransferRoute, out: TransferRoute, shared: ChargeItem, private: ChargeItem}
 */
function transferFixture(bool $featureOn = true): array
{
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    PropertySetting::factory()->for($property)->create(['currency' => 'MYR']);
    BusinessDate::factory()->for($property)->create(['date' => '2026-10-10']);

    if ($featureOn) {
        Feature::for($property)->activate(Transfers::class);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->for($reservation)->create([
        'property_id' => $property->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-23',
        'adults' => 2,
        'children' => 1,
        'status' => UnitStatus::Confirmed,
    ]);

    $shared = ChargeItem::factory()->for($property)->create(['name' => 'Airport van', 'default_amount' => '40.00', 'charging_unit' => ChargingUnit::PerGuest]);
    $private = ChargeItem::factory()->for($property)->create(['name' => 'Private car', 'default_amount' => '150.00', 'charging_unit' => ChargingUnit::PerStay]);

    return [
        'owner' => $owner,
        'property' => $property,
        'reservation' => $reservation,
        'unit' => $unit,
        'in' => TransferRoute::factory()->for($property)->create(['name' => 'Airport to hotel', 'shared_charge_item_id' => $shared->id, 'private_charge_item_id' => $private->id]),
        'out' => TransferRoute::factory()->for($property)->out()->create(['name' => 'Hotel to airport']),
        'shared' => $shared,
        'private' => $private,
    ];
}

/**
 * The set route of one unit and direction.
 *
 * @param  array<string, mixed>  $fixture
 */
function transferSetUrl(array $fixture, ReservationUnit $unit, string $direction): string
{
    return route('reservations.units.transfers.set', ['property' => $fixture['property']->code, 'reservation' => $unit->reservation_id, 'unit' => $unit->id, 'direction' => $direction]);
}
