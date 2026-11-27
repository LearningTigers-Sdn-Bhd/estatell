<?php

namespace App\Data\Reservations;

use App\Data\Boats\BoatTripData;
use App\Enums\StayType;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

/**
 * One line of a new reservation: a room type and rate plan for a stay. A Bed room type sells `beds` units, one per bed.
 * `overrideTotal` is a decimal string, the stay total of one unit. `arrivalTime` and `departureTime` are `H:i`; when null,
 * `AddUnit` copies the first unit of the reservation, then the property times. A day use line has `stayType` DayUse and
 * `arrivalDate` equal to `departureDate`. It also has `startTime` (`H:i`), `hours` and `roomId`; the times of the unit come
 * from them, and `beds`, `overrideTotal` and `discountId` stay empty. A whole-dorm line has `wholeRoomId`: `beds` is that
 * dorm's active bed count and `overrideTotal` is the stay total of the whole dorm, split over its beds. See
 * docs/12-reservations/02-group-bookings.md and docs/12-reservations/03-bed-bookings.md.
 */
class NewUnitData extends Data
{
    /**
     * @param  list<int>  $childAges
     * @param  list<NewAddOnData>  $addOns
     * @param  list<int>  $declinedAutoAddOns  Auto-add charge item ids staff removed from this room on the booking form.
     * @param  list<BoatTripData>  $boatTrips  Boat in and Boat out trips of this unit, set by `SetBoatTrip` inside the unit's transaction.
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
        public ?string $arrivalTime = null,
        public ?string $departureTime = null,
        public StayType $stayType = StayType::Overnight,
        public ?string $startTime = null,
        public ?int $hours = null,
        public ?int $roomId = null,
        public array $declinedAutoAddOns = [],
        public array $boatTrips = [],
        public ?int $wholeRoomId = null,
    ) {}
}
