<?php

namespace App\Data\Facilities;

use App\Models\FacilityBoat;
use Spatie\LaravelData\Data;

/** A boat on the Facilities settings Boats tab and in the Activities run sheet picker. */
class FacilityBoatData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $seats,
        public bool $active,
    ) {}

    public static function fromModel(FacilityBoat $boat): self
    {
        return new self(id: $boat->id, name: $boat->name, seats: $boat->seats, active: $boat->active);
    }
}
