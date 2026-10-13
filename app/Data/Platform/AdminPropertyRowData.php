<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use Spatie\LaravelData\Data;

class AdminPropertyRowData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public BadgeData $status,
        public ?string $type,
        public ?string $city,
        public ?string $ownerName,
        public ?string $ownerEmail,
        public string $createdAt,
    ) {}

    /**
     * Expects `owner_name` and `owner_email` from `PropertyOwners::addOwnerColumns()`.
     */
    public static function fromModel(Property $property): self
    {
        return new self(
            code: $property->code,
            name: $property->name,
            status: BadgeData::fromStatus($property->status),
            type: $property->type?->label(),
            city: $property->city,
            ownerName: $property->getAttribute('owner_name'),
            ownerEmail: $property->getAttribute('owner_email'),
            createdAt: $property->created_at->toDateString(),
        );
    }
}
