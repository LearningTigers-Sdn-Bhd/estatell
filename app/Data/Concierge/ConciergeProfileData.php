<?php

namespace App\Data\Concierge;

use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * The property profile fields the guest guide reads, shown read-only on the Concierge tabs. They are edited on the property profile.
 */
class ConciergeProfileData extends Data
{
    public function __construct(
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $postalCode,
        public ?string $city,
        public ?string $googleMapsUrl,
        public ?string $phone,
        public ?string $email,
    ) {}

    public static function fromProperty(Property $property): self
    {
        return new self(
            $property->address_line1,
            $property->address_line2,
            $property->postal_code,
            $property->city,
            $property->google_maps_url,
            $property->contact_phone,
            $property->contact_email,
        );
    }
}
