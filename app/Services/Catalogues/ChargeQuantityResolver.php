<?php

namespace App\Services\Catalogues;

use App\Enums\ChargingUnit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

/**
 * Turns a charging unit and a unit's stay into add-on rows. Nightly units give one row per night on or after the
 * business date, and ignore `$quantity` and `$serviceDate`. See docs/12-reservations/04-add-ons.md.
 */
class ChargeQuantityResolver
{
    /**
     * @return list<array{service_date: CarbonImmutable|null, quantity: int}>
     */
    public function rows(ChargingUnit $chargingUnit, ReservationUnit $unit, CarbonImmutable $businessDate, ?int $quantity = null, ?CarbonImmutable $serviceDate = null): array
    {
        $guests = $unit->adults + $unit->children;

        return match ($chargingUnit) {
            ChargingUnit::PerNight => $this->nightly($unit, $businessDate, 1),
            ChargingUnit::PerGuestNight => $this->nightly($unit, $businessDate, $guests),
            ChargingUnit::PerItem => [['service_date' => $serviceDate, 'quantity' => $quantity ?? 1]],
            ChargingUnit::PerStay => [['service_date' => $serviceDate, 'quantity' => 1]],
            ChargingUnit::PerGuest => [['service_date' => $serviceDate, 'quantity' => $guests]],
        };
    }

    /**
     * @return list<array{service_date: CarbonImmutable|null, quantity: int}>
     */
    private function nightly(ReservationUnit $unit, CarbonImmutable $businessDate, int $quantity): array
    {
        $rows = [];
        $departure = $unit->departure_date->toImmutable();

        for ($night = $unit->arrival_date->toImmutable(); $night->lt($departure); $night = $night->addDay()) {
            if ($night->gte($businessDate)) {
                $rows[] = ['service_date' => $night, 'quantity' => $quantity];
            }
        }

        return $rows;
    }
}
