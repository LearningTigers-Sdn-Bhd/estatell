<?php

namespace App\Data\Facilities;

use App\Models\FacilityGuide;
use Spatie\LaravelData\Data;

/** A guide on the Facilities settings Guides tab and in the Activities run sheet picker. */
class FacilityGuideData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $phone,
        public bool $active,
    ) {}

    public static function fromModel(FacilityGuide $guide): self
    {
        return new self(id: $guide->id, name: $guide->name, phone: $guide->phone, active: $guide->active);
    }
}
