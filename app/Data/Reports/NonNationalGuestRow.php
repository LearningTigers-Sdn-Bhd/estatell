<?php

namespace App\Data\Reports;

use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Non-national guests report: a reservation unit whose nationality is foreign. The property names are
 * the column keys of `ReportKey::NonNationalGuests`. `key` is the reservation unit id. `nationality` is the country
 * name and `identity` the ID with its number already masked or full for the user who asked (see `GuestIdentities`);
 * `id_type` and `id_number` are the two parts of `identity`, with the same masking.
 * `nights` counts the nights of the stay inside the dates; `tourism_tax` is the tourism tax posted in the dates.
 */
class NonNationalGuestRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $reservation,
        public string $guest,
        public string $nationality,
        public string $identity,
        public string $id_type,
        public string $id_number,
        public ?string $room,
        public string $arrival,
        public string $arrival_time,
        public string $departure,
        public string $departure_time,
        public int $nights,
        public string $guests,
        public int $adults,
        public int $children,
        public string $tourism_tax,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'reservation' => $this->reservation,
            'guest' => $this->guest,
            'nationality' => $this->nationality,
            'identity' => $this->identity,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'room' => $this->room ?? '',
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'arrival_time' => $this->arrival_time,
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'departure_time' => $this->departure_time,
            'nights' => (string) $this->nights,
            'guests' => $this->guests,
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
            'tourism_tax' => number_format((float) $this->tourism_tax, 2),
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
            'nationality' => $this->nationality,
            'identity' => $this->identity,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'room' => $this->room ?? '',
            'arrival' => $this->arrival,
            'arrival_time' => $this->arrival_time,
            'departure' => $this->departure,
            'departure_time' => $this->departure_time,
            'nights' => $this->nights,
            'guests' => $this->guests,
            'adults' => $this->adults,
            'children' => $this->children,
            'tourism_tax' => round((float) $this->tourism_tax, 2),
        ];
    }

    public static function fromUnit(ReservationUnit $unit, string $guest, string $nationality, string $identity, string $idType, string $idNumber, ?string $room, int $nights, string $tourismTax): self
    {
        return new self(
            key: $unit->id,
            reservation: $unit->reservation->reference,
            guest: $guest,
            nationality: $nationality,
            identity: $identity,
            id_type: $idType,
            id_number: $idNumber,
            room: $room,
            arrival: $unit->arrival_date->toDateString(),
            arrival_time: substr($unit->arrival_time, 0, 5),
            departure: $unit->departure_date->toDateString(),
            departure_time: substr($unit->departure_time, 0, 5),
            nights: $nights,
            guests: "{$unit->adults} / {$unit->children}",
            adults: $unit->adults,
            children: $unit->children,
            tourism_tax: $tourismTax,
        );
    }
}
