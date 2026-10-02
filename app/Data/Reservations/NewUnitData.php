<?php

namespace App\Data\Reservations;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * One line of a new reservation: a room type and rate plan for a stay. A Bed room type sells `beds` units, one per bed.
 * `overrideTotal` is a decimal string, the stay total of one unit. See docs/12-reservations/02-group-bookings.md.
 */
class NewUnitData extends Data
{
    /**
     * @param  list<int>  $childAges
     * @param  list<NewAddOnData>  $addOns
     */
    public function __construct(
        public int $roomTypeId,
        public int $ratePlanId,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public CarbonImmutable $arrivalDate,
        #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
        public CarbonImmutable $departureDate,
        public int $adults,
        public array $childAges = [],
        public int $beds = 1,
        public ?int $discountId = null,
        public bool $lateArrivalExpected = false,
        public ?string $overrideTotal = null,
        public ?string $overrideReason = null,
        public ?string $restrictionOverrideReason = null,
        public array $addOns = [],
    ) {}
}
