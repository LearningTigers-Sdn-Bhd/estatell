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
    ) {}

    public static function fromModel(Property $property): self
    {
        $logo = $property->getFirstMedia(Property::LOGO);

        return new self(
            code: $property->code,
            name: $property->name,
            status: $property->status,
            logoUrl: $logo === null ? null : route('property.logo', ['property' => $property->code, 'v' => $logo->id], absolute: false),
        );
    }
}
