<?php

namespace App\Data\Boats;

use App\Models\Boat;
use Spatie\LaravelData\Data;

/** One boat on the Boats tab and in the manifest's boat select. */
class BoatData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $registration,
        public ?int $seats,
        public bool $active,
    ) {}

    public static function fromModel(Boat $boat): self
    {
        return new self($boat->id, $boat->name, $boat->registration, $boat->seats, $boat->active);
    }
}
