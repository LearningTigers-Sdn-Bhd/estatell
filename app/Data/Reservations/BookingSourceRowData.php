<?php

namespace App\Data\Reservations;

use App\Models\BookingSource;
use Spatie\LaravelData\Data;

/**
 * One row of Settings → Booking sources. Expects the `otaChannel` relation to be loaded.
 */
class BookingSourceRowData extends Data
{
    public function __construct(
        public int $id,
        public BookingSourceBadgeData $badge,
        public string $typeLabel,
        public ?int $otaChannelId,
        public ?string $otaChannelName,
        public bool $isSystem,
        public bool $isActive,
        public int $usedCount,
    ) {}

    public static function fromModel(BookingSource $source, int $usedCount): self
    {
        return new self(
            id: $source->id,
            badge: BookingSourceBadgeData::fromModel($source),
            typeLabel: $source->type->label(),
            otaChannelId: $source->ota_channel_id,
            otaChannelName: $source->otaChannel?->name,
            isSystem: $source->is_system,
            isActive: $source->is_active,
            usedCount: $usedCount,
        );
    }
}
