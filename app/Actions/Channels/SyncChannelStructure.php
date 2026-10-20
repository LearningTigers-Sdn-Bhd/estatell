<?php

namespace App\Actions\Channels;

use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelSellMode;
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
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncChannelStructure
{
    public function __construct(
        private ChannelStructurePreview $preview,
        private ChannelCapability $capability,
        private ChannexStructureMapper $mapper,
        private ChannelDriverManager $drivers,
        private QueueChannelFullSync $queueFullSync,
        private QueueChannelPairRefresh $queuePairRefresh,
    ) {}

    /**
     * Send the property, its room types and its rate plans to the channel manager. Running it again updates the same
     * records and never duplicates them. The Channex ids are saved even when the sync stops part-way.
     *
     * Returns a failed result, without writing, when the channel manager is not set up, another sync of this property
     * is running or the preview is blocked. A Channex failure saves what was done and records the error. `$onProgress`
     * is passed to the driver and called with the steps done and the steps in all. The sync runs in the background
     * (`StartChannelStructureSync` queues it), and the lock outlasts the job's timeout.
     */
    public function __invoke(User $admin, Property $property, ?Closure $onProgress = null): ChannelActionResult
    {
        if (blank(config('services.channex.api_key'))) {
            return ChannelActionResult::failed(__('Channex is not set up on this server.'));
        }

        $lock = Cache::lock("channel-structure-sync:{$property->id}", 900);

        if (! $lock->get()) {
            return ChannelActionResult::failed(__('A sync of this property is already running.'));
        }

        try {
            return $this->sync($admin, $property, $onProgress);
        } finally {
            $lock->release();
        }
    }

    private function sync(User $admin, Property $property, ?Closure $onProgress): ChannelActionResult
    {
        $property->loadMissing('settings');
        $structure = $this->preview->forProperty($property);

        if ($structure->blockedReason !== null) {
            return ChannelActionResult::failed($structure->blockedReason);
        }

        $channelProperty = $property->channelProperty()->with(['roomTypeMappings', 'ratePlanMappings'])->first();

        try {
            $result = $this->drivers->forProvider(ChannelProvider::Channex)->syncStructure($this->request($property, $structure, $channelProperty), $onProgress);
        } catch (ChannelStructureSyncFailed $failure) {
            $this->save($admin, $property, $failure->partial, ChannelPropertyStatus::Error, $failure->getMessage(), $this->sellModes($structure), $channelProperty === null);

            return ChannelActionResult::failed($failure->getMessage());
        }

        $this->save($admin, $property, $result, ChannelPropertyStatus::Synced, null, $this->sellModes($structure), $channelProperty === null);

        if ($channelProperty === null) {
            $this->queueFullSync($property);
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
                $key = "{$roomTypeId}:{$pair->ratePlan->id}";
                $ratePlans[$pair->ratePlan->id] = [
                    'external_id' => $ratePlanIds[$key] ?? null,
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
     * @param  array<string, ChannelSellMode>  $sellModes
     * @param  bool  $firstSync  The first sync queues the full sync. After it, a plan that is new, in another mode or under another id has its rates queued again, because a new Channex plan starts at rate 0.
     */
    private function save(User $admin, Property $property, array $result, ChannelPropertyStatus $status, ?string $error, array $sellModes, bool $firstSync): void
    {
        if ($result['property_id'] === null) {
            return;
        }

        $changedModes = [];

        DB::transaction(function () use ($admin, $property, $result, $status, $error, $sellModes, $firstSync, &$changedModes): void {
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
                    $sellMode = $sellModes["{$roomTypeId}:{$ratePlanId}"] ?? ChannelSellMode::PerRoom;
                    $mapping = ChannelRatePlanMapping::query()->updateOrCreate(
                        ['channel_property_id' => $channelProperty->id, 'rate_plan_id' => $ratePlanId, 'room_type_id' => $roomTypeId],
                        ['external_rate_plan_id' => $externalId, 'sell_mode' => $sellMode],
                    );

                    if (! $firstSync && ($mapping->wasRecentlyCreated || $mapping->wasChanged(['sell_mode', 'external_rate_plan_id']))) {
                        $changedModes[] = [$roomTypeId, $ratePlanId];
                    }
                }
            }

            activity()
                ->performedOn($property)
                ->causedBy($admin)
                ->event($status === ChannelPropertyStatus::Synced ? 'channel.structure_synced' : 'channel.structure_sync_failed')
                ->withProperties(['property_id' => $property->id, 'room_types' => count($result['room_types']), 'error' => $error])
                ->log($status === ChannelPropertyStatus::Synced ? 'Structure synced to Channex' : 'Structure sync to Channex failed');
        });

        if ($changedModes !== []) {
            ($this->queuePairRefresh)($property->channelProperty()->with('property.settings')->firstOrFail(), $changedModes);
        }
    }

    /**
     * How each sent pair is sold, by "room type id:rate plan id". The ARI values follow what was sent.
     *
     * @return array<string, ChannelSellMode>
     */
    private function sellModes(ChannelStructure $structure): array
    {
        $modes = [];

        foreach ($structure->sent() as $pair) {
            $modes["{$pair->roomType->id}:{$pair->ratePlan->id}"] = $this->capability->sellMode($pair->ratePlan, $pair->roomType);
        }

        return $modes;
    }

    /**
     * The first sync queues the 500-day full sync.
     */
    private function queueFullSync(Property $property): void
    {
        $channelProperty = $property->channelProperty()->with('property')->first();

        if ($channelProperty !== null) {
            ($this->queueFullSync)($channelProperty);
        }
    }
}
