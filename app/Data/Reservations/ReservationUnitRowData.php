<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Services\Reservations\StayLabel;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One row of the All view on the Units tab. Expects `ratePlan`, `nights` and `dayUse` to be loaded, and takes the
 * label, room and primary guest from the unit's `ReservationUnitOptionData`. `ratePlan` reads "Several plans" when the
 * nights have more than one plan. `total` is the sum of the night amounts (or the day use price), before tax and folio
 * discounts. `selectable` is false for a Cancelled or NoShow unit, which no bulk action applies to.
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
        public bool $selectable,
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
            selectable: ! in_array($unit->status, [UnitStatus::Cancelled, UnitStatus::NoShow], true),
        );
    }
}
