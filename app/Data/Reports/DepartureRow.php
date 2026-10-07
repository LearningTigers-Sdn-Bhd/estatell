<?php

namespace App\Data\Reports;

use App\Data\Shared\BadgeData;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of the Departures report. The property names are the column keys of `ReportKey::Departures`.
 * `key` is the reservation unit id.
 */
class DepartureRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $reservation,
        public string $guest,
        public ?string $phone,
        public string $departure,
        public int $nights,
        public string $unit,
        public string $guests,
        public ?string $company,
        public BadgeData $status,
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
            'phone' => $this->phone ?? '',
            'departure' => CarbonImmutable::parse($this->departure)->format('j M Y'),
            'nights' => (string) $this->nights,
            'unit' => $this->unit,
            'guests' => $this->guests,
            'company' => $this->company ?? '',
            'status' => $this->status->label,
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
            'phone' => $this->phone ?? '',
            'departure' => $this->departure,
            'nights' => $this->nights,
            'unit' => $this->unit,
            'guests' => $this->guests,
            'company' => $this->company ?? '',
            'status' => $this->status->label,
            'balance' => round((float) $this->balance, 2),
        ];
    }

    /**
     * @param  string|null  $room  The room number or bed label of the last night, when one is assigned.
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
            departure: $unit->departure_date->toDateString(),
            nights: (int) $unit->arrival_date->diffInDays($unit->departure_date),
            unit: $room === null ? $unit->roomType->name : "{$unit->roomType->name} / {$room}",
            guests: "{$unit->adults} / {$unit->children}",
            company: $reservation->company?->name,
            status: BadgeData::fromUnitStatus($unit->status),
            balance: $balance,
        );
    }
}
