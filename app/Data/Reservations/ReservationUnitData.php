<?php

namespace App\Data\Reservations;

use App\Data\Boats\UnitBoatTripData;
use App\Data\Shared\BadgeData;
use App\Enums\BoatDirection;
use App\Enums\StayType;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitChild;
use App\Models\UnitNight;
use App\Services\Reservations\StayLabel;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * The unit panel of the Units tab. Expects `roomType`, `ratePlan`, `childAges`, `guests.guest` and `nights` with their
 * `room`, `bed` and `ratePlan` to be loaded. `assigned` lists the distinct rooms or beds of the nights, and
 * `allNightsAssigned` says whether every night has one. A day use unit has no nights: its `assigned` is its room, `total` is its block
 * price and `stayLabel` reads "Day use · 4h" (`nightCount` is 0); an overnight unit has no `stayLabel`. `actions` is the unit ⋯ menu the user may use.
 * `canChangePrice` shows Change price on the night table, and `chargedNightIds` marks the nights already charged.
 * `boatIn` and `boatOut` are the boat trips, read when `boatTrips` is loaded (otherwise null), and `canSetBoatIn` and
 * `canSetBoatOut` say whether the unit panel shows Change for them. `hasPriceOverride` shows the price override note,
 * with `priceOverrideReason` when one was given.
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
        public StayType $stayType,
        public ?string $stayLabel,
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
        public bool $canChangePrice,
        public ?UnitBoatTripData $boatIn = null,
        public ?UnitBoatTripData $boatOut = null,
        public bool $canSetBoatIn = false,
        public bool $canSetBoatOut = false,
        public bool $hasPriceOverride = false,
    ) {}

    /**
     * @param  list<UnitActionData>  $actions
     * @param  list<int>  $chargedNightIds
     */
    public static function fromModel(ReservationUnit $unit, bool $canViewGuest, ?string $overrideReason, ?string $overrideBy, array $actions = [], bool $canChangePrice = false, array $chargedNightIds = [], bool $canSetBoatIn = false, bool $canSetBoatOut = false, bool $hasPriceOverride = false): self
    {
        $isDayUse = $unit->stay_type === StayType::DayUse;
        $trips = $unit->relationLoaded('boatTrips') ? $unit->boatTrips : collect();
        $dayUse = $isDayUse ? $unit->loadMissing('dayUse.room')->dayUse : null;
        $nights = $unit->nights->sortBy('date')->values();
        $assigned = $isDayUse
            ? collect([$dayUse?->room?->number])
            : $nights->map(fn (UnitNight $night): ?string => $night->room->number ?? $night->bed?->label);
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
            stayType: $unit->stay_type,
            stayLabel: app(StayLabel::class)->forUnit($unit),
            nightCount: $nights->count(),
            adults: $unit->adults,
            children: $unit->children,
            childAges: array_values($unit->childAges->map(fn (ReservationUnitChild $child): int => $child->age)->sort()->all()),
            assigned: array_values($assigned->filter()->unique()->all()),
            allNightsAssigned: ($isDayUse || $nights->isNotEmpty()) && $assigned->every(fn (?string $value): bool => $value !== null),
            primaryGuest: $primary?->full_name,
            primaryGuestId: $canViewGuest ? $primary?->id : null,
            total: $nights->reduce(fn (BigDecimal $sum, UnitNight $night): BigDecimal => $sum->plus($night->amount), BigDecimal::of($dayUse->amount ?? '0'))->toScale(2)->__toString(),
            nights: array_values($nights->map(fn (UnitNight $night): ReservationNightData => ReservationNightData::fromModel($night, in_array($night->id, $chargedNightIds, true)))->all()),
            priceOverrideReason: $overrideReason,
            priceOverrideBy: $overrideBy,
            actions: $actions,
            canChangePrice: $canChangePrice,
            boatIn: $isDayUse ? null : UnitBoatTripData::forDirection($trips, BoatDirection::In, $unit),
            boatOut: $isDayUse ? null : UnitBoatTripData::forDirection($trips, BoatDirection::Out, $unit),
            canSetBoatIn: $canSetBoatIn,
            canSetBoatOut: $canSetBoatOut,
            hasPriceOverride: $hasPriceOverride,
        );
    }
}
