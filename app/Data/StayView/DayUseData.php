<?php

namespace App\Data\StayView;

use App\Data\Reservations\BookingSourceBadgeData;
use App\Data\Shared\BadgeData;
use App\Models\UnitDayUse;
use Spatie\LaravelData\Data;

/**
 * A live day use block with a room, in the window. It has no nights, so it is drawn in the third quarter of its date
 * cell, whatever its hours. `startsAt` and `endsAt` are `H:i`. It carries no contact or ID data.
 */
class DayUseData extends Data
{
    public function __construct(
        public int $unitId,
        public int $reservationId,
        public string $reference,
        public ?string $guestLabel,
        public BookingSourceBadgeData $source,
        public BadgeData $status,
        public int $roomId,
        public string $date,
        public string $startsAt,
        public string $endsAt,
        public int $hours,
    ) {}

    /**
     * Expects `reservationUnit.reservation.bookingSource` and the primary `reservationUnit.guests.guest` loaded.
     */
    public static function fromModel(UnitDayUse $block): self
    {
        $unit = $block->reservationUnit;

        return new self(
            unitId: $unit->id,
            reservationId: $unit->reservation_id,
            reference: $unit->reservation->reference,
            guestLabel: $unit->guests->first()?->guest?->full_name,
            source: BookingSourceBadgeData::fromModel($unit->reservation->bookingSource),
            status: BadgeData::fromUnitStatus($unit->status),
            roomId: (int) $block->room_id,
            date: $block->date->toDateString(),
            startsAt: substr($block->starts_at, 0, 5),
            endsAt: substr($block->ends_at, 0, 5),
            hours: $block->hours,
        );
    }
}
