<?php

namespace App\Data\Transfers;

use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Models\ReservationUnit;
use App\Models\UnitTransfer;
use Spatie\LaravelData\Data;

/**
 * One row of the reservation Transfers tab: a unit and a direction, and its transfer when set (otherwise the transfer
 * fields are null). `date` is the unit's arrival date (In) or departure date (Out). Load the transfer with
 * `transferRoute`, `transferVehicle` and `transferDriver`.
 */
class UnitTransferRowData extends Data
{
    public function __construct(
        public int $unitId,
        public string $unitLabel,
        public TransferDirection $direction,
        public string $directionLabel,
        public string $date,
        public ?TransferMode $mode,
        public ?string $modeLabel,
        public ?int $routeId,
        public ?string $routeName,
        public ?string $pickupTime,
        public ?string $flightNumber,
        public ?int $bags,
        public ?string $note,
        public ?string $vehicle,
        public ?string $driver,
        public bool $canEdit,
    ) {}

    public static function fromUnit(ReservationUnit $unit, string $unitLabel, TransferDirection $direction, ?UnitTransfer $transfer, bool $canEdit): self
    {
        $driver = $transfer?->transferDriver;

        return new self(
            unitId: $unit->id,
            unitLabel: $unitLabel,
            direction: $direction,
            directionLabel: $direction->label(),
            date: ($direction === TransferDirection::In ? $unit->arrival_date : $unit->departure_date)->toDateString(),
            mode: $transfer?->mode,
            modeLabel: $transfer?->mode->label(),
            routeId: $transfer?->transfer_route_id,
            routeName: $transfer?->transferRoute->name,
            pickupTime: $transfer === null ? null : substr($transfer->pickup_time, 0, 5),
            flightNumber: $transfer?->flight_number,
            bags: $transfer?->bags,
            note: $transfer?->note,
            vehicle: $transfer?->transferVehicle?->name,
            driver: $driver === null ? null : ($driver->subcontractor === null ? $driver->name : "{$driver->name} ({$driver->subcontractor})"),
            canEdit: $canEdit,
        );
    }
}
