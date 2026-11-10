<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\SellUnit;
use App\Models\ReservationUnit;
use App\Services\Reservations\UnitCapacity;
use Spatie\LaravelData\Data;

/**
 * One unit in the Choose unit list, the Status by unit popover and the guest rows: room type, the room or bed of its
 * first night, status and dates. Expects `roomType` to be loaded. `label` is "Room type · 101" (a dash when unassigned). `capacity` is how
 * many guests the unit holds (`UnitCapacity`), so the Stays in select disables a full unit. `primaryGuest` is sent for the Choose unit list.
 */
class ReservationUnitOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $roomType,
        public bool $isBed,
        public int $capacity,
        public ?string $assigned,
        public ?string $primaryGuest,
        public string $label,
        public BadgeData $status,
        public string $arrival,
        public string $departure,
        public string $occupancy,
    ) {}

    public static function fromModel(ReservationUnit $unit, ?string $assigned, ?string $primaryGuest = null): self
    {
        return new self(
            id: $unit->id,
            roomType: $unit->roomType->name,
            isBed: $unit->roomType->sell_unit === SellUnit::Bed,
            capacity: app(UnitCapacity::class)->of($unit),
            assigned: $assigned,
            primaryGuest: $primaryGuest,
            label: $unit->roomType->name.' · '.($assigned ?? __('Not assigned')),
            status: BadgeData::fromUnitStatus($unit->status),
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
            occupancy: trans_choice(':count adult|:count adults', $unit->adults)
                .($unit->children > 0 ? ' · '.trans_choice(':count child|:count children', $unit->children) : ''),
        );
    }
}
