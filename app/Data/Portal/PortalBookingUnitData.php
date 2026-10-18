<?php

namespace App\Data\Portal;

use App\Data\Shared\BadgeData;
use App\Models\ReservationUnit;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One room or bed of a booking: the room type, the plan, the dates, the guests' names, the add-ons and the total of
 * the rooms and add-ons. No room number, folio or staff note.
 */
class PortalBookingUnitData extends Data
{
    /**
     * @param  list<string>  $guestNames
     * @param  list<PortalBookingAddOnData>  $addOns
     */
    public function __construct(
        public int $id,
        public string $roomType,
        public string $ratePlan,
        public string $arrivalDate,
        public string $departureDate,
        public int $nights,
        public int $adults,
        public int $children,
        public BadgeData $status,
        public array $guestNames,
        public array $addOns,
        public string $total,
    ) {}

    /**
     * Load `roomType`, `ratePlan`, `nights`, `dayUse`, `guests.guest` and `addOns.chargeItem` first.
     */
    public static function fromUnit(ReservationUnit $unit): self
    {
        $rooms = $unit->nights->reduce(fn (BigDecimal $sum, $night): BigDecimal => $sum->plus($night->amount), BigDecimal::of($unit->dayUse->amount ?? '0'));
        $addOns = $unit->addOns->reduce(fn (BigDecimal $sum, $addOn): BigDecimal => $sum->plus(BigDecimal::of($addOn->unit_amount)->multipliedBy($addOn->quantity)), BigDecimal::zero());

        return new self(
            id: $unit->id,
            roomType: $unit->roomType->name,
            ratePlan: $unit->ratePlan->name,
            arrivalDate: $unit->arrival_date->toDateString(),
            departureDate: $unit->departure_date->toDateString(),
            nights: $unit->nights->count(),
            adults: $unit->adults,
            children: $unit->children,
            status: BadgeData::fromUnitStatus($unit->status),
            guestNames: array_values($unit->guests->map(fn ($occupant): string => $occupant->guest->full_name)->all()),
            addOns: array_values($unit->addOns->map(fn ($addOn): PortalBookingAddOnData => PortalBookingAddOnData::fromAddOn($addOn))->all()),
            total: (string) $rooms->plus($addOns)->toScale(2),
        );
    }
}
