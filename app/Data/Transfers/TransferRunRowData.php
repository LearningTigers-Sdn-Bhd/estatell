<?php

namespace App\Data\Transfers;

use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use App\Models\UnitTransfer;
use Spatie\LaravelData\Data;

/**
 * One transfer on the run sheet. `guest` is the booker's name, or null when the user may not see guests. Load the
 * transfer with `transferRoute` and `reservationUnit.reservation.booker`.
 */
class TransferRunRowData extends Data
{
    public function __construct(
        public int $id,
        public int $reservationId,
        public string $reference,
        public ?string $guest,
        public TransferDirection $direction,
        public string $directionLabel,
        public TransferMode $mode,
        public string $modeLabel,
        public string $routeName,
        public string $place,
        public ?int $minutes,
        public string $pickupTime,
        public int $pax,
        public ?string $flightNumber,
        public ?string $note,
        public ?int $vehicleId,
        public ?int $driverId,
    ) {}

    public static function fromModel(UnitTransfer $transfer, bool $canViewGuest): self
    {
        $unit = $transfer->reservationUnit;
        $route = $transfer->transferRoute;

        return new self(
            id: $transfer->id,
            reservationId: $unit->reservation_id,
            reference: $unit->reservation->reference,
            guest: $canViewGuest ? $unit->reservation->booker->full_name : null,
            direction: $transfer->direction,
            directionLabel: $transfer->direction->label(),
            mode: $transfer->mode,
            modeLabel: $transfer->mode->label(),
            routeName: $route->name,
            place: $route->place,
            minutes: $route->minutes,
            pickupTime: substr($transfer->pickup_time, 0, 5),
            pax: $unit->adults + $unit->children,
            flightNumber: $transfer->flight_number,
            note: $transfer->note,
            vehicleId: $transfer->transfer_vehicle_id,
            driverId: $transfer->transfer_driver_id,
        );
    }
}
