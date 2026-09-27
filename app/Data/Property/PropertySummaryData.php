<?php

namespace App\Data\Property;

use App\Enums\PropertyStatus;
use App\Models\Property;
use Spatie\LaravelData\Data;

class PropertySummaryData extends Data
{
    public function __construct(
        public int $code,
        public string $name,
        public PropertyStatus $status,
    ) {}

    public static function fromModel(Property $property): self
    {
        return new self(
            code: $property->code,
            name: $property->name,
            status: $property->status,
        );
    }
}
