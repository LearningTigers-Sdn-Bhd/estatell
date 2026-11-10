<?php

namespace App\Data\Reservations;

use App\Data\Boats\UnitBoatTripData;
use App\Data\Shared\BadgeData;
use App\Enums\BoatDirection;
use App\Enums\StayType;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Services\Reservations\StayLabel;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One row of the All view on the Units tab. Expects `ratePlan`, `nights` and `dayUse` to be loaded, and takes the
 * label, room and primary guest from the unit's `ReservationUnitOptionData`. `ratePlan` reads "Several plans" when the
 * nights have more than one plan. `total` is the sum of the night amounts (or the day use price), before tax and folio
 * discounts. `boatIn` and `boatOut` are the short boat texts (`UnitBoatTripData::$short`, "06:00 boat"), null when
 * the unit has no trip that way, and always null when `boatTrips` is not loaded (the Boats feature is off) or on day
 * use. Expects `boatTrips.boatDeparture` with the trips.
 */
class ReservationUnitRowData extends Data
{
    public function __construct(
        public int $id,
        public string $roomType,
        public ?string $assigned,
        public ?string $primaryGuest,
        public int $adults,
        public int $children,
        public string $arrival,
        public string $departure,
        public int $nightCount,
        public ?string $stayLabel,
        public string $ratePlan,
        public string $total,
        public BadgeData $status,
        public ?string $boatIn = null,
        public ?string $boatOut = null,
    ) {}

    public static function fromModel(ReservationUnit $unit, ReservationUnitOptionData $option): self
    {
        $stayLabel = app(StayLabel::class);
        $plans = $unit->nights->pluck('rate_plan_id')->unique();

        return new self(
            id: $unit->id,
            roomType: $option->roomType,
            assigned: $option->assigned,
            primaryGuest: $option->primaryGuest,
            adults: $unit->adults,
            children: $unit->children,
            arrival: $option->arrival,
            departure: $option->departure,
            nightCount: $unit->nights->count(),
            stayLabel: $stayLabel->forUnit($unit),
            ratePlan: $plans->count() > 1 ? __('Several plans') : $stayLabel->planForUnit($unit),
            total: $unit->nights
                ->reduce(fn (BigDecimal $sum, UnitNight $night): BigDecimal => $sum->plus($night->amount), BigDecimal::of($unit->dayUse->amount ?? '0'))
                ->toScale(2)
                ->__toString(),
            status: $option->status,
            boatIn: self::boat($unit, BoatDirection::In),
            boatOut: self::boat($unit, BoatDirection::Out),
        );
    }

    private static function boat(ReservationUnit $unit, BoatDirection $direction): ?string
    {
        if (! $unit->relationLoaded('boatTrips') || $unit->stay_type === StayType::DayUse) {
            return null;
        }

        return UnitBoatTripData::forDirection($unit->boatTrips, $direction, $unit)?->short;
    }
}
