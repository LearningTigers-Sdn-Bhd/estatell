<?php

namespace App\Data\Group;

use App\Data\Property\CurrencyData;
use Spatie\LaravelData\Data;

/**
 * The group dashboard's totals across the properties shown, each property counted on its own business date. A figure
 * is null when no property shows it. Occupancy is the units sold tonight over the units on sale, summed, not an
 * average of percentages. Revenue is summed only when every property uses the same currency; `revenueProperties` says
 * how many of the `properties` shown it covers (a role that hides revenue at one leaves it out).
 */
class GroupTotalsData extends Data
{
    public function __construct(
        public ?int $arrivalsRemaining,
        public ?int $arrivalsTotal,
        public ?int $departuresRemaining,
        public ?int $departuresTotal,
        public ?int $occupancy,
        public ?int $unitsSold,
        public ?int $unitsOnSale,
        public ?int $newBookings,
        public ?int $newRooms,
        public ?string $monthPosted,
        public ?string $monthBookedAmount,
        public ?CurrencyData $currency,
        public int $revenueProperties,
        public int $properties,
    ) {}
}
