<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Models\ReservationUnit;
use Spatie\LaravelData\Data;

/**
 * One row of the Arrivals report. The property names are the column keys of `ReportKey::Arrivals`.
 * `key` is the reservation unit id.
 */
class ArrivalRow extends Data
{
    public function __construct(
        public int $key,
        public string $reservation,
        public string $guest,
        public ?string $phone,
        public string $arrival,
        public int $nights,
        public string $unit,
        public string $guests,
        public ?string $company,
        public BadgeData $status,
        public string $balance,
    ) {}

    /**
     * @param  string|null  $room  The room number or bed label of the first night, when one is assigned.
     */
    public static function fromModel(ReservationUnit $unit, ?string $room, string $balance): self
    {
        $reservation = $unit->reservation;
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())?->guest ?? $reservation->booker;

        return new self(
            key: $unit->id,
            reservation: $reservation->reference,
            guest: $occupant->full_name,
            phone: $occupant->phone,
            arrival: $unit->arrival_date->toDateString(),
            nights: (int) $unit->arrival_date->diffInDays($unit->departure_date),
            unit: $room === null ? $unit->roomType->name : "{$unit->roomType->name} / {$room}",
            guests: "{$unit->adults} / {$unit->children}",
            company: $reservation->company?->name,
            status: BadgeData::fromUnitStatus($unit->status),
            balance: $balance,
        );
    }
}
