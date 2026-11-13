<?php

namespace App\Data\Concierge;

use App\Models\Amenity;
use Spatie\LaravelData\Data;

/**
 * One amenity the property offers, with the guest details staff edit on the Amenities tab.
 */
class ConciergeAmenitySettingsData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $category,
        public ?string $location,
        public ?string $openingHours,
        public ?string $feeInformation,
        public bool $reservationRequired,
        public ?string $reservationInstructions,
        public ?string $guestNotes,
    ) {}

    public static function fromModel(Amenity $amenity): self
    {
        return new self(
            $amenity->id,
            $amenity->name,
            $amenity->category->name,
            $amenity->pivot->location,
            $amenity->pivot->opening_hours,
            $amenity->pivot->fee_information,
            (bool) $amenity->pivot->reservation_required,
            $amenity->pivot->reservation_instructions,
            $amenity->pivot->guest_notes,
        );
    }
}
