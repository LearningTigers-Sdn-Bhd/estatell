<?php

namespace App\Data\Reports;

use App\Models\ReservationUnit;
use App\Models\UnitTransfer;
use Spatie\LaravelData\Data;

/**
 * One transfer of the Transfer manifest: a room's road transfer in or out on the date. The property names are the column
 * keys of `ReportKey::TransferManifest`. `key` is the transfer id. `vehicle_id` and `driver_id` group the row into its
 * section (one per vehicle and driver); they are not columns. `lead_guest` and `phone` are null when the user may not
 * see guests, as on the run sheet.
 */
class TransferManifestRow extends Data implements ReportRow
{
    public function __construct(
        public int $key,
        public string $pickup,
        public string $direction,
        public string $route,
        public string $place,
        public string $unit,
        public ?string $lead_guest,
        public ?string $phone,
        public int $adults,
        public int $children,
        public int $total,
        public ?int $bags,
        public ?string $flight,
        public string $mode,
        public ?string $vehicle,
        public ?string $driver,
        public string $reservation,
        public ?string $note,
        public ?int $vehicle_id = null,
        public ?int $driver_id = null,
    ) {}

    /**
     * The row of one transfer. The lead guest is the unit's primary guest, else its first guest, else the booker (the
     * Boat manifest rule). Load the transfer with `transferRoute`, `transferVehicle` and `transferDriver`, and the unit
     * with `reservation.booker`, `roomType` and `guests.guest`.
     *
     * @param  string|null  $room  The room number or bed label of the night the direction needs, when one is assigned.
     */
    public static function fromModel(UnitTransfer $transfer, ReservationUnit $unit, ?string $room, bool $canViewGuest): self
    {
        $occupant = ($unit->guests->firstWhere('is_primary', true) ?? $unit->guests->first())->guest ?? $unit->reservation->booker;
        $route = $transfer->transferRoute;
        $vehicle = $transfer->transferVehicle;
        $driver = $transfer->transferDriver;

        return new self(
            key: $transfer->id,
            pickup: substr($transfer->pickup_time, 0, 5),
            direction: $transfer->direction->label(),
            route: $route->name,
            place: $route->place,
            unit: $room ?? $unit->roomType->name,
            lead_guest: $canViewGuest ? $occupant->full_name : null,
            phone: $canViewGuest ? $occupant->phone : null,
            adults: $unit->adults,
            children: $unit->children,
            total: $unit->adults + $unit->children,
            bags: $transfer->bags,
            flight: $transfer->flight_number,
            mode: $transfer->mode->label(),
            vehicle: $vehicle === null ? null : ($vehicle->plate === null ? $vehicle->name : "{$vehicle->name} · {$vehicle->plate}"),
            driver: $driver === null ? null : ($driver->subcontractor === null ? $driver->name : "{$driver->name} ({$driver->subcontractor})"),
            reservation: $unit->reservation->reference,
            note: $transfer->note,
            vehicle_id: $vehicle?->id,
            driver_id: $driver?->id,
        );
    }

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return array_map(fn (int|float|string $value): string => (string) $value, $this->values());
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'pickup' => $this->pickup,
            'direction' => $this->direction,
            'route' => $this->route,
            'place' => $this->place,
            'unit' => $this->unit,
            'lead_guest' => $this->lead_guest ?? '',
            'phone' => $this->phone ?? '',
            'adults' => $this->adults,
            'children' => $this->children,
            'total' => $this->total,
            'bags' => $this->bags ?? '',
            'flight' => $this->flight ?? '',
            'mode' => $this->mode,
            'vehicle' => $this->vehicle ?? '',
            'driver' => $this->driver ?? '',
            'reservation' => $this->reservation,
            'note' => $this->note ?? '',
        ];
    }
}
