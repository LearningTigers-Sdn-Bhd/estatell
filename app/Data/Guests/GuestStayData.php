<?php

namespace App\Data\Guests;

use App\Data\Shared\BadgeData;
use App\Enums\StayType;
use App\Models\ReservationUnit;
use App\Models\UnitNight;
use App\Services\Guests\GuestStayHistory;
use Brick\Math\BigDecimal;
use Spatie\LaravelData\Data;

/**
 * One stay on the guest page Stays tab. Built from a unit of `GuestStayHistory::stays()`, which loads its relations and
 * selects `is_primary` and `spend`. `spend` is null when the guest is not the primary guest, so a shared room counts once.
 */
class GuestStayData extends Data
{
    public function __construct(
        public int $unitId,
        public int $reservationId,
        public string $shortCode,
        public string $arrival,
        public string $departure,
        public bool $isDayUse,
        public BadgeData $status,
        public string $room,
        public ?string $source,
        public int $nights,
        public bool $isPrimary,
        public ?string $spend,
    ) {}

    public static function fromUnit(ReservationUnit $unit): self
    {
        $isPrimary = (bool) $unit->getAttribute('is_primary');

        return new self(
            unitId: $unit->id,
            reservationId: $unit->reservation_id,
            shortCode: $unit->reservation->short_code,
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
            isDayUse: $unit->stay_type === StayType::DayUse,
            status: BadgeData::fromUnitStatus($unit->status),
            room: self::room($unit),
            source: $unit->reservation->company->name ?? $unit->reservation->bookingSource?->name,
            nights: app(GuestStayHistory::class)->nights($unit),
            isPrimary: $isPrimary,
            spend: $isPrimary ? (string) BigDecimal::of((string) ($unit->getAttribute('spend') ?? '0'))->toScale(2) : null,
        );
    }

    /**
     * The room of the last night with a room or bed, the day use room, or the room type name.
     */
    private static function room(ReservationUnit $unit): string
    {
        $night = $unit->nights->last(fn (UnitNight $night): bool => $night->room !== null || $night->bed !== null);

        return $night?->room->number
            ?? ($night?->bed !== null ? trim($night->bed->room->number.' · '.$night->bed->label) : null)
            ?? $unit->dayUse?->room->number
            ?? $unit->roomType->name;
    }
}
