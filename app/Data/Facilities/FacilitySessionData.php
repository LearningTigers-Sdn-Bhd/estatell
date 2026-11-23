<?php

namespace App\Data\Facilities;

use App\Models\FacilitySession;
use Spatie\LaravelData\Data;

/** One fixed time of a Shared facility, as `HH:MM`. */
class FacilitySessionData extends Data
{
    public function __construct(
        public int $id,
        public string $startsAt,
        public string $endsAt,
    ) {}

    public static function fromModel(FacilitySession $session): self
    {
        return new self($session->id, substr($session->starts_at, 0, 5), substr($session->ends_at, 0, 5));
    }
}
