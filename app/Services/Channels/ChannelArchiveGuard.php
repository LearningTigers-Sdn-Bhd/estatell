<?php

namespace App\Services\Channels;

use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * Knows whether a room type, rate plan or pair is still on the channel manager. Staff cannot archive or unlink it
 * until it is removed there: a request that finds it on the channel manager queues the removal first
 * (`QueueChannelRemoval`), and the Actions refuse it as a last check. Mapped OTA rates are checked here too once OTAs
 * can be connected.
 */
class ChannelArchiveGuard
{
    public function roomTypeOnChannel(RoomType $roomType): bool
    {
        return ChannelRoomTypeMapping::query()->where('room_type_id', $roomType->id)->exists()
            || ChannelRatePlanMapping::query()->where('room_type_id', $roomType->id)->exists();
    }

    public function ratePlanOnChannel(RatePlan $ratePlan): bool
    {
        return ChannelRatePlanMapping::query()->where('rate_plan_id', $ratePlan->id)->exists();
    }

    /**
     * @param  list<int>  $removedRoomTypeIds
     */
    public function unlinkOnChannel(RatePlan $ratePlan, array $removedRoomTypeIds): bool
    {
        return $removedRoomTypeIds !== []
            && ChannelRatePlanMapping::query()->where('rate_plan_id', $ratePlan->id)->whereIn('room_type_id', $removedRoomTypeIds)->exists();
    }

    public function roomTypeMessage(RoomType $roomType): ?string
    {
        return $this->roomTypeOnChannel($roomType) ? $this->message($roomType->name) : null;
    }

    public function ratePlanMessage(RatePlan $ratePlan): ?string
    {
        return $this->ratePlanOnChannel($ratePlan) ? $this->message($ratePlan->name) : null;
    }

    /**
     * Unlinking room types from a plan removes those pairs, so a pair that is synced blocks it.
     *
     * @param  list<int>  $removedRoomTypeIds
     */
    public function unlinkMessage(RatePlan $ratePlan, array $removedRoomTypeIds): ?string
    {
        return $this->unlinkOnChannel($ratePlan, $removedRoomTypeIds) ? $this->message($ratePlan->name) : null;
    }

    private function message(string $name): string
    {
        return __(':name is on the channel manager. Ask the estatell team to remove it there first.', ['name' => $name]);
    }
}
