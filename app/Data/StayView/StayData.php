<?php

namespace App\Data\StayView;

use App\Data\Reservations\BookingSourceBadgeData;
use App\Data\Shared\BadgeData;
use App\Models\ReservationUnit;
use Spatie\LaravelData\Data;

/**
 * One reservation unit in the window. It carries no contact or ID data. Units of one group booking share
 * `reservationId` and `groupName`. `unitCount` is the units the whole reservation holds, not only those in the window. `balanceDue` is the reservation's, not the unit's.
 */
class StayData extends Data
{
    /**
     * @param  list<StaySegmentData>  $segments
     */
    public function __construct(
        public int $unitId,
        public int $reservationId,
        public string $reference,
        public ?string $guestLabel,
        public ?string $groupName,
        public int $unitCount,
        public BookingSourceBadgeData $source,
        public int $adults,
        public int $children,
        public BadgeData $status,
        public bool $balanceDue,
        public string $arrivalDate,
        public string $departureDate,
        public string $arrivalTime,
        public string $departureTime,
        public array $segments,
    ) {}

    /**
     * Expects `reservation.bookingSource`, `reservation.live_units_count` and the primary `guests.guest` loaded.
     *
     * @param  list<StaySegmentData>  $segments
     */
    public static function fromUnit(ReservationUnit $unit, array $segments, bool $balanceDue): self
    {
        return new self(
            unitId: $unit->id,
            reservationId: $unit->reservation_id,
            reference: $unit->reservation->reference,
            guestLabel: $unit->guests->first()?->guest?->full_name,
            groupName: $unit->reservation->group_name,
            unitCount: (int) $unit->reservation->getAttribute('live_units_count'),
            source: BookingSourceBadgeData::fromModel($unit->reservation->bookingSource),
            adults: $unit->adults,
            children: $unit->children,
            status: BadgeData::fromUnitStatus($unit->status),
            balanceDue: $balanceDue,
            arrivalDate: $unit->arrival_date->toDateString(),
            departureDate: $unit->departure_date->toDateString(),
            arrivalTime: substr($unit->arrival_time, 0, 5),
            departureTime: substr($unit->departure_time, 0, 5),
            segments: $segments,
        );
    }
}
