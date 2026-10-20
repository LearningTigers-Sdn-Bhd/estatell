<?php

namespace App\Actions\Channels;

use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\Property;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelCapability;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\ChannelStructure;
use App\Services\Channels\ChannelStructurePair;
use App\Services\Channels\ChannelStructurePreview;
use App\Services\Channels\ChannelStructureSyncFailed;
use App\Services\Channels\Channex\ChannexStructureMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncChannelStructure
{
    public function __construct(
        private ChannelStructurePreview $preview,
        private ChannelCapability $capability,
        private ChannexStructureMapper $mapper,
        private ChannelDriverManager $drivers,
    ) {}

    /**
     * Send the property, its room types and its rate plans to the channel manager. Running it again updates the same
     * records and never duplicates them. The Channex ids are saved even when the sync stops part-way.
     *
     * Returns a failed result, without writing, when the channel manager is not set up, another sync of this property
     * is running or the preview is blocked. A Channex failure saves what was done and records the error.
     */
    public function __invoke(User $admin, Property $property): ChannelActionResult
    {
        if (blank(config('services.channex.api_key'))) {
            return ChannelActionResult::failed(__('Channex is not set up on this server.'));
        }

        $lock = Cache::lock("channel-structure-sync:{$property->id}", 120);

        if (! $lock->get()) {
            return ChannelActionResult::failed(__('A sync of this property is already running.'));
        }

        try {
            return $this->sync($admin, $property);
        } finally {
            $lock->release();
        }
    }

    private function sync(User $admin, Property $property): ChannelActionResult
    {
        $property->loadMissing('settings');
        $structure = $this->preview->forProperty($property);

        if ($structure->blockedReason !== null) {
            return ChannelActionResult::failed($structure->blockedReason);
        }

        $channelProperty = $property->channelProperty()->with(['roomTypeMappings', 'ratePlanMappings'])->first();

        try {
            $result = $this->drivers->forProvider(ChannelProvider::Channex)->syncStructure($this->request($property, $structure, $channelProperty));
        } catch (ChannelStructureSyncFailed $failure) {
            $this->save($admin, $property, $failure->partial, ChannelPropertyStatus::Error, $failure->getMessage());

            return ChannelActionResult::failed($failure->getMessage());
        }

        $this->save($admin, $property, $result, ChannelPropertyStatus::Synced, null);

        if ($channelProperty === null) {
            $this->queueFullSync();
        }

        return ChannelActionResult::ok(__(':rooms room types and :plans rate plans sent to Channex.', ['rooms' => $structure->roomTypeCount(), 'plans' => $structure->ratePlanCount()]));
    }

    /**
     * @return array{property: array{external_id: string|null, payload: array<string, mixed>}, room_types: array<int, array{external_id: string|null, payload: array<string, mixed>, rate_plans: array<int, array{external_id: string|null, payload: array<string, mixed>}>}>}
     */
    private function request(Property $property, ChannelStructure $structure, ?ChannelProperty $channelProperty): array
    {
        $roomTypeIds = $channelProperty === null ? [] : $channelProperty->roomTypeMappings->pluck('external_room_type_id', 'room_type_id')->all();
        $ratePlanIds = [];

        foreach ($channelProperty->ratePlanMappings ?? [] as $mapping) {
            $ratePlanIds["{$mapping->room_type_id}:{$mapping->rate_plan_id}"] = $mapping->external_rate_plan_id;
        }

        $currency = $property->settings->currency->getCurrencyCode();
        $roomTypes = [];

        foreach ($structure->sent()->groupBy(fn (ChannelStructurePair $pair): int => $pair->roomType->id) as $roomTypeId => $pairs) {
            $roomType = $pairs->first()->roomType;
            $ratePlans = [];

            foreach ($pairs as $pair) {
                $ratePlans[$pair->ratePlan->id] = [
                    'external_id' => $ratePlanIds["{$roomTypeId}:{$pair->ratePlan->id}"] ?? null,
                    'payload' => $this->mapper->ratePlan($pair->ratePlan, $roomType, $pair->result, $currency),
                ];
            }

            $roomTypes[$roomTypeId] = [
                'external_id' => $roomTypeIds[$roomTypeId] ?? null,
                'payload' => $this->mapper->roomType($roomType, $this->capability->activeRoomCount($roomType)),
                'rate_plans' => $ratePlans,
            ];
        }

        return [
            'property' => ['external_id' => $channelProperty?->external_property_id, 'payload' => $this->mapper->property($property)],
            'room_types' => $roomTypes,
        ];
    }

    /**
     * Save the ids Channex gave and the sync state. A failure before the property exists at Channex saves nothing.
     *
     * @param  array{property_id: string|null, room_types: array<int, array{id: string, rate_plans: array<int, string>}>}  $result
     */
    private function save(User $admin, Property $property, array $result, ChannelPropertyStatus $status, ?string $error): void
    {
        if ($result['property_id'] === null) {
            return;
        }

        DB::transaction(function () use ($admin, $property, $result, $status, $error): void {
            $channelProperty = ChannelProperty::query()->updateOrCreate(
                ['property_id' => $property->id],
                [
                    'provider' => ChannelProvider::Channex,
                    'external_property_id' => $result['property_id'],
                    'status' => $status,
                    'last_error' => $error,
                    ...($status === ChannelPropertyStatus::Synced ? ['structure_synced_at' => now()] : []),
                ],
            );

            foreach ($result['room_types'] as $roomTypeId => $roomType) {
                ChannelRoomTypeMapping::query()->updateOrCreate(
                    ['channel_property_id' => $channelProperty->id, 'room_type_id' => $roomTypeId],
                    ['external_room_type_id' => $roomType['id']],
                );

                foreach ($roomType['rate_plans'] as $ratePlanId => $externalId) {
                    ChannelRatePlanMapping::query()->updateOrCreate(
                        ['channel_property_id' => $channelProperty->id, 'rate_plan_id' => $ratePlanId, 'room_type_id' => $roomTypeId],
                        ['external_rate_plan_id' => $externalId],
                    );
                }
            }

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event($status === ChannelPropertyStatus::Synced ? 'channel.structure_synced' : 'channel.structure_sync_failed')
                ->withProperties(['property_id' => $property->id, 'room_types' => count($result['room_types']), 'error' => $error])
                ->log($status === ChannelPropertyStatus::Synced ? 'Structure synced to Channex' : 'Structure sync to Channex failed');
        });
    }

    /**
     * The first sync queues the 500-day full sync. That job arrives with the availability and rates commit.
     */
    private function queueFullSync(): void
    {
        // Wired in "sync availability, rates and restrictions".
    }
}
