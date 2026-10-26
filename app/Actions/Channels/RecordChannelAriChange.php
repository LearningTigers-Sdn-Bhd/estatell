<?php

namespace App\Actions\Channels;

use App\Enums\AriChangeKind;
use App\Enums\ChannelPropertyStatus;
use App\Events\InventoryChanged;
use App\Events\RatesChanged;
use App\Events\ReservationChanged;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelAriChange;
use App\Models\ChannelProperty;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Services\Channels\ChannelAriRanges;
use App\Services\Channels\ChannelAriWindow;
use Carbon\CarbonImmutable;

class RecordChannelAriChange
{
    public function __construct(private ChannelAriWindow $window, private ChannelAriRanges $ranges) {}

    /**
     * Note a changed date range in the ARI outbox, for every mapped room type or rate plan pair it touches, and
     * start the delayed flush after the surrounding transaction commits, so a rolled-back change (the walk-in payment
     * preview) queues nothing. A property that is not on a channel manager, or a range outside the next 500 days,
     * records nothing. A waiting line of the same room type, plan and kind that overlaps or touches the range grows
     * instead of a new line being added.
     */
    public function __invoke(RatesChanged|InventoryChanged|ReservationChanged $event): void
    {
        $channelProperty = ChannelProperty::query()
            ->with('property.settings')
            ->where('property_id', $event->propertyId)
            ->whereIn('status', [ChannelPropertyStatus::Synced, ChannelPropertyStatus::Paused])
            ->first();

        if ($channelProperty === null) {
            return;
        }

        $range = $this->window->clamp($channelProperty->property, $event->dateFrom, $event->dateTo);

        if ($range === null) {
            return;
        }

        [$from, $to] = $range;

        foreach ($this->pairs($channelProperty, $event) as [$roomTypeId, $ratePlanId, $kind]) {
            $this->record($channelProperty, $roomTypeId, $ratePlanId, $kind, $from, $to);
        }

        if ($channelProperty->status === ChannelPropertyStatus::Synced) {
            FlushChannelAriJob::dispatch($channelProperty->id)->delay(now()->addSeconds(random_int(10, 30)))->afterCommit();
        }
    }

    /**
     * Every mapped room type and plan pair the event touches. A derived plan follows its parent's prices.
     *
     * @return array<int, array{0: int, 1: int|null, 2: AriChangeKind}>
     */
    private function pairs(ChannelProperty $channelProperty, RatesChanged|InventoryChanged|ReservationChanged $event): array
    {
        if (! $event instanceof RatesChanged) {
            return ChannelRoomTypeMapping::query()
                ->where('channel_property_id', $channelProperty->id)
                ->when($event->roomTypeId !== null, fn ($query) => $query->where('room_type_id', $event->roomTypeId))
                ->pluck('room_type_id')
                ->map(fn (int $roomTypeId): array => [$roomTypeId, null, AriChangeKind::Availability])
                ->all();
        }

        return ChannelRatePlanMapping::query()
            ->with('ratePlan:id,parent_rate_plan_id')
            ->where('channel_property_id', $channelProperty->id)
            ->when($event->roomTypeId !== null, fn ($query) => $query->where('room_type_id', $event->roomTypeId))
            ->get()
            ->filter(fn (ChannelRatePlanMapping $mapping): bool => $event->ratePlanId === null
                || $mapping->rate_plan_id === $event->ratePlanId
                || $mapping->ratePlan->parent_rate_plan_id === $event->ratePlanId)
            ->map(fn (ChannelRatePlanMapping $mapping): array => [$mapping->room_type_id, $mapping->rate_plan_id, AriChangeKind::Rates])
            ->values()
            ->all();
    }

    private function record(ChannelProperty $channelProperty, int $roomTypeId, ?int $ratePlanId, AriChangeKind $kind, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $waiting = ChannelAriChange::query()
            ->where('channel_property_id', $channelProperty->id)
            ->where('room_type_id', $roomTypeId)
            ->where('rate_plan_id', $ratePlanId)
            ->where('kind', $kind)
            ->waiting()
            ->whereNull('last_error')
            ->where('date_from', '<=', $to->addDay()->toDateString())
            ->where('date_to', '>=', $from->subDay()->toDateString())
            ->first();

        if ($waiting === null) {
            ChannelAriChange::query()->create([
                'channel_property_id' => $channelProperty->id,
                'room_type_id' => $roomTypeId,
                'rate_plan_id' => $ratePlanId,
                'kind' => $kind,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ]);

            return;
        }

        [[$mergedFrom, $mergedTo]] = $this->ranges->merge([
            [$waiting->date_from->toDateString(), $waiting->date_to->toDateString()],
            [$from->toDateString(), $to->toDateString()],
        ]);
        $waiting->update(['date_from' => $mergedFrom, 'date_to' => $mergedTo]);
    }
}
