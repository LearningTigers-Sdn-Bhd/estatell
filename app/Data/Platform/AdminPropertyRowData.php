<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\Property;
use Spatie\LaravelData\Data;

class AdminPropertyRowData extends Data
{
    public function __construct(
        public int $code,
        public string $name,
        public BadgeData $status,
        public ?string $type,
        public ?string $city,
        public int $staffCount,
        public string $createdAt,
    ) {}

    /**
     * Expects `users_count` from `withCount('users')`.
     */
    public static function fromModel(Property $property): self
    {
        return new self(
            code: $property->code,
            name: $property->name,
            status: BadgeData::fromStatus($property->status),
            type: $property->type?->label(),
            city: $property->city,
            staffCount: (int) $property->getAttribute('users_count'),
            createdAt: $property->created_at->toDateString(),
        );
    }
}
