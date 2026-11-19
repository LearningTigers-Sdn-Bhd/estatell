<?php

namespace App\Data\Property;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Spatie\LaravelData\Data;

class PropertySummaryData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public PropertyStatus $status,
        public ?string $logoUrl,
        public ?string $countryCode,
    ) {}

    public static function fromModel(Property $property, bool $forGuest = false): self
    {
        $logo = $property->getFirstMedia(Property::LOGO);
        // A guest has no login, so the Concierge pages point at the logo route that needs none.
        $logoRoute = $forGuest ? 'concierge.logo' : 'property.logo';

        return new self(
            code: $property->code,
            name: $property->name,
            status: $property->status,
            logoUrl: $logo === null ? null : route($logoRoute, ['property' => $property->code, 'v' => $logo->id], absolute: false),
            countryCode: $property->country_code,
        );
    }
}
