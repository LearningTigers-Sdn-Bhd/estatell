<?php

namespace App\Data\Concierge;

use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * What staff edit on the Guest guidelines tab: the arrival and departure steps, as stored.
 */
class ConciergeGuestGuidelinesData extends Data
{
    public function __construct(
        public ?string $arrivalInstructions,
        public ?string $departureInstructions,
    ) {}

    public static function fromProperty(Property $property): self
    {
        return new self(
            $property->settings?->arrival_instructions,
            $property->settings?->departure_instructions,
        );
    }
}
