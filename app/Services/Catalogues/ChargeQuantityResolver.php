<?php

namespace App\Services\Catalogues;

use App\Enums\AgeBandPricing;
use App\Enums\ChargingUnit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;

/**
 * Turns a charging unit and a unit's stay into add-on rows. Nightly units give one row per night on or after the
 * business date, and ignore `$quantity` and `$serviceDate`. With a `ChildPriceSplit`, a per-guest unit writes an
 * adult row and one row per billable age group (`age_group_id` and `child_price` set on those rows).
 * See docs/12-reservations/04-add-ons.md.
 */
class ChargeQuantityResolver
{
    /**
     * @return list<array{service_date: CarbonImmutable|null, quantity: int, age_group_id: int|null, child_price: array{pricing: AgeBandPricing, value: string|null}|null}>
     */
    public function rows(ChargingUnit $chargingUnit, ReservationUnit $unit, CarbonImmutable $businessDate, ?int $quantity = null, ?CarbonImmutable $serviceDate = null, ?ChildPriceSplit $split = null): array
    {
        $guests = $unit->adults + $unit->children;

        return match ($chargingUnit) {
            ChargingUnit::PerNight => $this->nightly($unit, $businessDate, [['quantity' => 1, 'group' => null]]),
            ChargingUnit::PerGuestNight => $this->nightly($unit, $businessDate, $this->guestRows($unit, $guests, $split)),
            ChargingUnit::PerItem => $this->undated($serviceDate, [['quantity' => $quantity ?? 1, 'group' => null]]),
            ChargingUnit::PerStay => $this->undated($serviceDate, [['quantity' => 1, 'group' => null]]),
            ChargingUnit::PerGuest => $this->undated($serviceDate, $this->guestRows($unit, $guests, $split)),
        };
    }

    /**
     * The adult row, then one row per billable age group. Without a split every guest is one adult row.
     *
     * @return list<array{quantity: int, group: array{age_group_id: int, pricing: AgeBandPricing, value: string|null}|null}>
     */
    private function guestRows(ReservationUnit $unit, int $guests, ?ChildPriceSplit $split): array
    {
        if ($split === null) {
            return [['quantity' => $guests, 'group' => null]];
        }

        $rows = [['quantity' => $unit->adults + $split->adultChildren, 'group' => null]];

        foreach ($split->groups as $group) {
            $rows[] = ['quantity' => $group['count'], 'group' => $group];
        }

        return array_values(array_filter($rows, fn (array $row): bool => $row['quantity'] > 0));
    }

    /**
     * @param  list<array{quantity: int, group: array{age_group_id: int, pricing: AgeBandPricing, value: string|null}|null}>  $parts
     * @return list<array{service_date: CarbonImmutable|null, quantity: int, age_group_id: int|null, child_price: array{pricing: AgeBandPricing, value: string|null}|null}>
     */
    private function undated(?CarbonImmutable $serviceDate, array $parts): array
    {
        return array_map(fn (array $part): array => $this->row($serviceDate, $part), $parts);
    }

    /**
     * @param  list<array{quantity: int, group: array{age_group_id: int, pricing: AgeBandPricing, value: string|null}|null}>  $parts
     * @return list<array{service_date: CarbonImmutable|null, quantity: int, age_group_id: int|null, child_price: array{pricing: AgeBandPricing, value: string|null}|null}>
     */
    private function nightly(ReservationUnit $unit, CarbonImmutable $businessDate, array $parts): array
    {
        $rows = [];
        $departure = $unit->departure_date->toImmutable();

        for ($night = $unit->arrival_date->toImmutable(); $night->lt($departure); $night = $night->addDay()) {
            if ($night->gte($businessDate)) {
                foreach ($parts as $part) {
                    $rows[] = $this->row($night, $part);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array{quantity: int, group: array{age_group_id: int, pricing: AgeBandPricing, value: string|null}|null}  $part
     * @return array{service_date: CarbonImmutable|null, quantity: int, age_group_id: int|null, child_price: array{pricing: AgeBandPricing, value: string|null}|null}
     */
    private function row(?CarbonImmutable $serviceDate, array $part): array
    {
        return [
            'service_date' => $serviceDate,
            'quantity' => $part['quantity'],
            'age_group_id' => $part['group']['age_group_id'] ?? null,
            'child_price' => $part['group'] === null ? null : ['pricing' => $part['group']['pricing'], 'value' => $part['group']['value']],
        ];
    }
}
