<?php

namespace App\Data\Transfers;

use App\Models\TransferDriver;
use Spatie\LaravelData\Data;

/**
 * One driver on the Drivers tab and in the run sheet's driver select. `label` adds the subcontractor in brackets:
 * "Ah Seng (Kinabatangan Tours)".
 */
class TransferDriverData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public ?string $phone,
        public ?string $subcontractor,
        public bool $active,
    ) {}

    public static function fromModel(TransferDriver $driver): self
    {
        return new self(
            id: $driver->id,
            name: $driver->name,
            label: $driver->subcontractor === null ? $driver->name : "{$driver->name} ({$driver->subcontractor})",
            phone: $driver->phone,
            subcontractor: $driver->subcontractor,
            active: $driver->active,
        );
    }
}
