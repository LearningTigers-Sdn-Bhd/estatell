<?php

namespace App\Data\Reports;

use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Non-national guests report: a reservation unit whose nationality is foreign. The property names are
 * the column keys of `ReportKey::NonNationalGuests`. `key` is the reservation unit id. `nationality` is the country
 * name and `identity` the ID with its number already masked or full for the user who asked (see `GuestIdentities`).
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
        public ?string $room,
        public string $arrival,
        public string $departure,
        public int $nights,
        public string $guests,
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
            'room' => $this->room ?? '',
            'arrival' => CarbonImmutable::parse($this->arrival)->format('j M Y'),
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'nights' => (string) $this->nights,
            'guests' => $this->guests,
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
            'room' => $this->room ?? '',
            'arrival' => $this->arrival,
            'departure' => $this->departure,
            'nights' => $this->nights,
            'guests' => $this->guests,
            'tourism_tax' => round((float) $this->tourism_tax, 2),
        ];
    }

    public static function fromUnit(ReservationUnit $unit, string $guest, string $nationality, string $identity, ?string $room, int $nights, string $tourismTax): self
    {
        return new self(
            key: $unit->id,
            reservation: $unit->reservation->reference,
            guest: $guest,
            nationality: $nationality,
            identity: $identity,
            room: $room,
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
            nights: $nights,
            guests: "{$unit->adults} / {$unit->children}",
            tourism_tax: $tourismTax,
        );
    }
}
