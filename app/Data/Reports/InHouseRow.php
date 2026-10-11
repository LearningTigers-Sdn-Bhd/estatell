<?php

namespace App\Data\Reports;

use App\Models\ReservationUnit;
use App\Services\Reservations\StayLabel;
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
        public string $arrival_time,
        public string $departure,
        public string $departure_time,
        public ?string $stay,
        public string $guests,
        public int $adults,
        public int $children,
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
            'arrival_time' => $this->arrival_time,
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'departure_time' => $this->departure_time,
            'stay' => $this->stay ?? '',
            'guests' => $this->guests,
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
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
            'arrival_time' => $this->arrival_time,
            'departure' => $this->departure,
            'departure_time' => $this->departure_time,
            'stay' => $this->stay ?? '',
            'guests' => $this->guests,
            'adults' => $this->adults,
            'children' => $this->children,
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
            arrival_time: substr($unit->arrival_time, 0, 5),
            departure: $unit->departure_date->toDateString(),
            departure_time: substr($unit->departure_time, 0, 5),
            stay: app(StayLabel::class)->hoursForUnit($unit),
            guests: "{$unit->adults} / {$unit->children}",
            adults: $unit->adults,
            children: $unit->children,
            company: $reservation->company?->name,
            balance: $balance,
        );
    }
}
