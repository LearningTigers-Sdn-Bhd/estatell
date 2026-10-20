<?php

namespace App\Services\Channels;

use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\RatePlan;
use App\Models\RoomType;

/**
 * Stops staff from archiving or unlinking a room type or rate plan that the channel manager still has. An admin removes
 * it from the channel manager first. Mapped OTA rates are checked here too once OTAs can be connected.
 */
class ChannelArchiveGuard
{
    public function roomTypeMessage(RoomType $roomType): ?string
    {
        $synced = ChannelRoomTypeMapping::query()->where('room_type_id', $roomType->id)->exists()
            || ChannelRatePlanMapping::query()->where('room_type_id', $roomType->id)->exists();

        return $synced ? $this->message($roomType->name) : null;
    }

    public function ratePlanMessage(RatePlan $ratePlan): ?string
    {
        return ChannelRatePlanMapping::query()->where('rate_plan_id', $ratePlan->id)->exists() ? $this->message($ratePlan->name) : null;
    }

    /**
     * Unlinking room types from a plan removes those pairs, so a pair that is synced blocks it.
     *
     * @param  list<int>  $removedRoomTypeIds
     */
    public function unlinkMessage(RatePlan $ratePlan, array $removedRoomTypeIds): ?string
    {
        if ($removedRoomTypeIds === []) {
            return null;
        }

        $synced = ChannelRatePlanMapping::query()
            ->where('rate_plan_id', $ratePlan->id)
            ->whereIn('room_type_id', $removedRoomTypeIds)
            ->exists();

        return $synced ? $this->message($ratePlan->name) : null;
    }

    private function message(string $name): string
    {
        return __(':name is on the channel manager. Ask the estatell team to remove it there first.', ['name' => $name]);
    }
}
