<?php

namespace App\Data\Reports;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use Spatie\LaravelData\Data;

/**
 * One trip of the Boat manifest: a unit's Boat in or Boat out on the date. The property names are the column keys of
 * `ReportKey::BoatManifest`. `key` is the trip id. `boat_departure_id` and `seats` group the row into its departure
 * (null for a charter or own boat trip). They are not columns.
 */
class BoatManifestRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $departure,
        public string $unit,
        public string $lead_guest,
        public ?string $phone,
        public int $adults,
        public int $children,
        public int $total,
        public string $reservation,
        public ?string $note,
        public ?int $boat_departure_id,
        public ?int $seats,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'departure' => $this->departure,
            'unit' => $this->unit,
            'lead_guest' => $this->lead_guest,
            'phone' => $this->phone ?? '',
            'adults' => (string) $this->adults,
            'children' => (string) $this->children,
            'total' => (string) $this->total,
            'reservation' => $this->reservation,
            'note' => $this->note ?? '',
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'departure' => $this->departure,
            'unit' => $this->unit,
            'lead_guest' => $this->lead_guest,
            'phone' => $this->phone ?? '',
            'adults' => $this->adults,
            'children' => $this->children,
            'total' => $this->total,
            'reservation' => $this->reservation,
            'note' => $this->note ?? '',
        ];
    }

    /**
     * The row of one trip. The lead guest is the unit's first guest (the primary guest first), else the booker. Charter
     * and own boat trips take their time from the unit: its arrival time for Boat in, its departure time for Boat out.
     *
     * @param  string|null  $room  The room number or bed label of the night the direction needs, when one is assigned.
     */
    public static function fromModel(UnitBoatTrip $trip, ReservationUnit $unit, ?string $room): self
    {
        $reservation = $unit->reservation;
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())?->guest ?? $reservation->booker;
        $departure = $trip->boatDeparture;
        $isScheduled = $trip->mode === BoatMode::Scheduled && $departure !== null;

        $time = substr($trip->direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5);

        return new self(
            key: $trip->id,
            departure: $isScheduled
                ? implode(' ', array_filter([substr($departure->leaves_at, 0, 5), $departure->pickup_point])).' → '.substr($departure->arrives_at, 0, 5)
                : ($trip->mode === BoatMode::Charter ? __('Charter :time', ['time' => $time]) : __('Own :time', ['time' => $time])),
            unit: $room ?? $unit->roomType->name,
            lead_guest: $occupant->full_name,
            phone: $occupant->phone,
            adults: $unit->adults,
            children: $unit->children,
            total: $unit->adults + $unit->children,
            reservation: $reservation->reference,
            note: $trip->note,
            boat_departure_id: $isScheduled ? $departure->id : null,
            seats: $isScheduled ? $departure->seats : null,
        );
    }
}
