<?php

namespace App\Data\Channels;

use App\Data\Shared\BadgeData;
use App\Enums\PricingBasis;
use App\Services\Channels\ChannelStructurePair;
use Spatie\LaravelData\Data;

/**
 * One room type and rate plan pair in the sync preview.
 */
class ChannelStructureRowData extends Data
{
    public function __construct(
        public string $roomType,
        public string $ratePlan,
        public string $sellMode,
        public BadgeData $support,
        public ?string $detail,
    ) {}

    public static function fromPair(ChannelStructurePair $pair): self
    {
        return new self(
            roomType: $pair->roomType->name,
            ratePlan: $pair->ratePlan->name,
            sellMode: self::sellMode($pair),
            support: BadgeData::fromChannelSupport($pair->result->support),
            detail: $pair->result->detail,
        );
    }

    private static function sellMode(ChannelStructurePair $pair): string
    {
        $basis = $pair->ratePlan->pricing_basis;

        return match ($basis) {
            PricingBasis::PerGuest => __('Per guest (1–:max)', ['max' => $pair->roomType->max_adults]),
            PricingBasis::Package => __('Package'),
            default => $basis->label(),
        };
    }
}
