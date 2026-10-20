<?php

namespace App\Data\Channels;

use App\Data\Shared\BadgeData;
use App\Enums\ChannelPropertyStatus;
use App\Models\ChannelProperty;
use App\Models\ChannelRoomTypeMapping;
use App\Services\Channels\ChannelStructure;
use Spatie\LaravelData\Data;

/**
 * The admin Channels tab: the Channex card, the sync preview and the synced items.
 */
class ChannelTabData extends Data
{
    /**
     * @param  list<ChannelSyncedRoomTypeData>  $synced
     */
    public function __construct(
        public BadgeData $status,
        public ?string $structureSyncedAt,
        public ?string $lastError,
        public ChannelStructurePreviewData $preview,
        public array $synced,
    ) {}

    public static function forProperty(?ChannelProperty $channelProperty, ChannelStructure $structure): self
    {
        return new self(
            status: BadgeData::fromChannelPropertyStatus($channelProperty === null ? ChannelPropertyStatus::NotSynced : $channelProperty->status),
            structureSyncedAt: $channelProperty?->structure_synced_at?->toIso8601String(),
            lastError: $channelProperty?->last_error,
            preview: ChannelStructurePreviewData::fromStructure($structure),
            synced: $channelProperty === null ? [] : self::synced($channelProperty),
        );
    }

    /**
     * @return list<ChannelSyncedRoomTypeData>
     */
    private static function synced(ChannelProperty $channelProperty): array
    {
        $plans = $channelProperty->ratePlanMappings()->with('ratePlan')->get()->groupBy('room_type_id');

        $synced = [];

        foreach ($channelProperty->roomTypeMappings()->with('roomType')->get()->sortBy(fn (ChannelRoomTypeMapping $mapping): int => $mapping->roomType->position) as $mapping) {
            $ratePlans = [];

            foreach ($plans->get($mapping->room_type_id) ?? [] as $plan) {
                $ratePlans[] = ['id' => $plan->id, 'name' => $plan->ratePlan->name];
            }

            $synced[] = ChannelSyncedRoomTypeData::fromMapping($mapping, $ratePlans);
        }

        return $synced;
    }
}
