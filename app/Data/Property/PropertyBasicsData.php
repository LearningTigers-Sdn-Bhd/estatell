<?php

namespace App\Data\Property;

use App\Enums\PropertyType;
use App\Models\Property;
use Spatie\LaravelData\Data;

class PropertyBasicsData extends Data
{
    public function __construct(
        public string $name,
        public ?PropertyType $type,
        public ?string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $stateCode,
        public ?string $postalCode,
    ) {}

    public static function fromModel(Property $property): self
    {
        return new self(
            name: $property->name,
            type: $property->type,
            addressLine1: $property->address_line1,
            addressLine2: $property->address_line2,
            city: $property->city,
            stateCode: $property->state_code,
            postalCode: $property->postal_code,
        );
    }
}
