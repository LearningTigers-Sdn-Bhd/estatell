<?php

namespace App\Data\BookingEngine;

use App\Data\Shared\BadgeData;
use App\Enums\NearbyPlaceStatus;
use App\Models\NearbyPlace;
use Spatie\LaravelData\Data;

/**
 * One row of the admin review page.
 */
class AdminNearbyPlaceData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $googleMapsUrl,
        public BadgeData $status,
        public NearbyPlaceStatus $statusValue,
        public int $propertiesCount,
        public ?string $addedBy,
        public string $createdAt,
        public bool $canApprove,
        public bool $canReject,
    ) {}

    public static function fromModel(NearbyPlace $place): self
    {
        return new self(
            id: $place->id,
            name: $place->name,
            googleMapsUrl: $place->google_maps_url,
            status: new BadgeData($place->status->value, $place->status->label(), $place->status->tone()),
            statusValue: $place->status,
            propertiesCount: (int) $place->getAttribute('properties_count'),
            addedBy: $place->createdByProperty?->name,
            createdAt: $place->created_at?->toIso8601String() ?? '',
            canApprove: $place->status->canTransitionTo(NearbyPlaceStatus::Approved),
            canReject: $place->status->canTransitionTo(NearbyPlaceStatus::Rejected),
        );
    }
}
