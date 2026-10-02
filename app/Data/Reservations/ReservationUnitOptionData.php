<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Enums\SellUnit;
use App\Models\ReservationUnit;
use Spatie\LaravelData\Data;

/**
 * One unit in the Choose unit list, the Status by unit popover and the guest rows: room type, the room or bed of its
 * first night, status and dates. Expects `roomType` to be loaded. `label` is "Room type · 101" (a dash when unassigned).
 */
class ReservationUnitOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $roomType,
        public bool $isBed,
        public ?string $assigned,
        public string $label,
        public BadgeData $status,
        public string $arrival,
        public string $departure,
    ) {}

    public static function fromModel(ReservationUnit $unit, ?string $assigned): self
    {
        return new self(
            id: $unit->id,
            roomType: $unit->roomType->name,
            isBed: $unit->roomType->sell_unit === SellUnit::Bed,
            assigned: $assigned,
            label: $unit->roomType->name.' · '.($assigned ?? '—'),
            status: BadgeData::fromUnitStatus($unit->status),
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
        );
    }
}
