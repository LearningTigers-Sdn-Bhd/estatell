<?php

namespace App\Data\Reports;

use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the In-house report. The property names are the column keys of `ReportKey::InHouse`.
 * `key` is the reservation unit id.
 */
class InHouseRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $reservation,
        public string $guest,
        public ?string $room,
        public string $arrival,
        public string $departure,
        public string $guests,
        public ?string $company,
        public string $balance,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'room' => $this->room ?? '',
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'guests' => $this->guests,
            'company' => $this->company ?? '',
            'balance' => number_format((float) $this->balance, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'room' => $this->room ?? '',
            'arrival' => $this->arrival,
            'departure' => $this->departure,
            'guests' => $this->guests,
            'company' => $this->company ?? '',
            'balance' => round((float) $this->balance, 2),
        ];
    }

    /**
     * @param  string|null  $room  The room number or bed label of the report date, when one is assigned.
     */
    public static function fromModel(ReservationUnit $unit, ?string $room, string $balance): self
    {
        $reservation = $unit->reservation;
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())?->guest ?? $reservation->booker;

        return new self(
            key: $unit->id,
            reservation: $reservation->reference,
            guest: $occupant->full_name,
            room: $room,
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
            guests: "{$unit->adults} / {$unit->children}",
            company: $reservation->company?->name,
            balance: $balance,
        );
    }
}
