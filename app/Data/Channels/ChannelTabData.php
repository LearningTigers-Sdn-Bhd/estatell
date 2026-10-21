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
     * @param  list<ChannelRevisionData>  $failedBookings
     * @param  list<ChannelAlertData>  $alerts
     * @param  list<ChannelRevisionData>  $recentBookings
     * @param  list<ChannelOtaRowData>  $otas
     */
    public function __construct(
        public BadgeData $status,
        public ?string $structureSyncedAt,
        public ?string $changesSentAt,
        public ?string $fullSyncedAt,
        public ?string $pausedUntil,
        public int $waitingChanges,
        public bool $canFullRefresh,
        public ?string $lastError,
        public ChannelStructurePreviewData $preview,
        public ChannelSyncProgressData $sync,
        public array $synced,
        public array $failedBookings,
        public array $alerts,
        public array $recentBookings,
        public array $otas,
    ) {}

    /**
     * @param  list<ChannelOtaRowData>  $otas
     */
    public static function forProperty(?ChannelProperty $channelProperty, ChannelStructure $structure, ChannelSyncProgressData $sync, array $otas = []): self
    {
        return new self(
            status: BadgeData::fromChannelPropertyStatus($channelProperty === null ? ChannelPropertyStatus::NotSynced : $channelProperty->status),
            structureSyncedAt: $channelProperty?->structure_synced_at?->toIso8601String(),
            changesSentAt: $channelProperty?->changes_sent_at?->toIso8601String(),
            fullSyncedAt: $channelProperty?->full_synced_at?->toIso8601String(),
            pausedUntil: $channelProperty?->paused_until?->isFuture() ? $channelProperty->paused_until->toIso8601String() : null,
            waitingChanges: $channelProperty === null ? 0 : $channelProperty->ariChanges()->waiting()->count(),
            canFullRefresh: $channelProperty?->status === ChannelPropertyStatus::Synced,
            lastError: $channelProperty?->last_error,
            preview: ChannelStructurePreviewData::fromStructure($structure),
            sync: $sync,
            synced: $channelProperty === null ? [] : self::synced($channelProperty),
            failedBookings: $channelProperty === null ? [] : self::failedBookings($channelProperty),
            alerts: $channelProperty === null ? [] : $channelProperty->alerts()->open()->latest('id')->get()->map(ChannelAlertData::fromModel(...))->all(),
            recentBookings: $channelProperty === null ? [] : self::recentBookings($channelProperty),
            otas: $otas,
        );
    }

    /**
     * @return list<ChannelRevisionData>
     */
    private static function failedBookings(ChannelProperty $channelProperty): array
    {
        return $channelProperty->bookingRevisions()->failed()->with('reservation')->oldest('id')->get()->map(ChannelRevisionData::fromModel(...))->all();
    }

    /**
     * @return list<ChannelRevisionData>
     */
    private static function recentBookings(ChannelProperty $channelProperty): array
    {
        return $channelProperty->bookingRevisions()->with('reservation')->latest('id')->limit(20)->get()->map(ChannelRevisionData::fromModel(...))->all();
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
