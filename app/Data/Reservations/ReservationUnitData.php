<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitChild;
use App\Models\UnitNight;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The unit panel of the Units tab. Expects `roomType`, `ratePlan`, `childAges`, `guests.guest` and `nights` with their
 * `room`, `bed` and `ratePlan` to be loaded. `assigned` lists the distinct rooms or beds of the nights, and
 * `allNightsAssigned` says whether every night has one. `actions` is the unit ⋯ menu the user may use.
 */
class ReservationUnitData extends Data
{
    /**
     * @param  list<int>  $childAges
     * @param  list<string>  $assigned
     * @param  list<ReservationNightData>  $nights
     * @param  list<UnitActionData>  $actions
     */
    public function __construct(
        public int $id,
        public string $roomType,
        public string $ratePlan,
        public BadgeData $status,
        public string $arrival,
        public string $departure,
        public string $arrivalTime,
        public string $departureTime,
        public int $nightCount,
        public int $adults,
        public int $children,
        public array $childAges,
        public array $assigned,
        public bool $allNightsAssigned,
        public ?string $primaryGuest,
        public ?int $primaryGuestId,
        public string $total,
        public array $nights,
        public ?string $priceOverrideReason,
        public ?string $priceOverrideBy,
        public array $actions,
    ) {}

    public static function fromModel(ReservationUnit $unit, bool $canViewGuest, ?string $overrideReason, ?string $overrideBy, array $actions = []): self
    {
        $nights = $unit->nights->sortBy('date')->values();
        $assigned = $nights->map(fn (UnitNight $night): ?string => $night->room?->number ?? $night->bed?->label);
        $primary = $unit->guests->firstWhere('is_primary', true)?->guest;

        return new self(
            id: $unit->id,
            roomType: $unit->roomType->name,
            ratePlan: $unit->ratePlan->name,
            status: BadgeData::fromUnitStatus($unit->status),
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
            arrivalTime: substr($unit->arrival_time, 0, 5),
            departureTime: substr($unit->departure_time, 0, 5),
            nightCount: $nights->count(),
            adults: $unit->adults,
            children: $unit->children,
            childAges: $unit->childAges->map(fn (ReservationUnitChild $child): int => $child->age)->sort()->values()->all(),
            assigned: $assigned->filter()->unique()->values()->all(),
            allNightsAssigned: $nights->isNotEmpty() && $assigned->every(fn (?string $value): bool => $value !== null),
            primaryGuest: $primary?->full_name,
            primaryGuestId: $canViewGuest ? $primary?->id : null,
            total: $nights->reduce(fn (BigDecimal $sum, UnitNight $night): BigDecimal => $sum->plus($night->amount), BigDecimal::zero())->toScale(2)->__toString(),
            nights: $nights->map(fn (UnitNight $night): ReservationNightData => ReservationNightData::fromModel($night))->all(),
            priceOverrideReason: $overrideReason,
            priceOverrideBy: $overrideBy,
            actions: $actions,
        );
    }
}
