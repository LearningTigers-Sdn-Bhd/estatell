<?php

namespace App\Data\Boats;

use App\Models\BoatCrewMember;
use Spatie\LaravelData\Data;

/** One crew member on the Crew tab and in the departure form's crew selects. */
class BoatCrewMemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $phone,
        public bool $active,
    ) {}

    public static function fromModel(BoatCrewMember $crewMember): self
    {
        return new self($crewMember->id, $crewMember->name, $crewMember->phone, $crewMember->active);
    }
}
