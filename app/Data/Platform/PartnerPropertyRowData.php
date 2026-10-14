<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use Spatie\LaravelData\Data;

class PartnerPropertyRowData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public BadgeData $status,
        public string $createdAt,
    ) {}

    public static function fromModel(Property $property): self
    {
        return new self(
            code: $property->code,
            name: $property->name,
            status: BadgeData::fromStatus($property->status),
            createdAt: $property->created_at->toDateString(),
        );
    }
}
