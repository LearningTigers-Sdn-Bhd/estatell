<?php

namespace App\Data\Transfers;

use App\Models\TransferVehicle;
use Spatie\LaravelData\Data;

/** One vehicle on the Vehicles tab and in the run sheet's vehicle select. */
class TransferVehicleData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $plate,
        public ?int $seats,
        public bool $active,
    ) {}

    public static function fromModel(TransferVehicle $vehicle): self
    {
        return new self($vehicle->id, $vehicle->name, $vehicle->plate, $vehicle->seats, $vehicle->active);
    }
}
