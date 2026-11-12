<?php

namespace App\Actions\Channels;

use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;

class RemoveChannelRoomTypeRatePlans
{
    public function __construct(private RemoveChannelRatePlan $removeChannelRatePlan) {}

    /**
     * Remove every rate plan of one room type from Channex, one at a time. It stops at the first rate plan Channex refuses
     * (an OTA may still use it): the ones removed before stay removed, and the result says how many went.
     */
    public function __invoke(User $admin, ChannelRoomTypeMapping $mapping): ChannelActionResult
    {
        $mapping->loadMissing('roomType');
        $name = $mapping->roomType->name;

        $ratePlans = ChannelRatePlanMapping::query()
            ->where('channel_property_id', $mapping->channel_property_id)
            ->where('room_type_id', $mapping->room_type_id)
            ->orderBy('id')
            ->get();

        if ($ratePlans->isEmpty()) {
            return ChannelActionResult::failed(__(':name has no rate plans on Channex.', ['name' => $name]));
        }

        $removed = 0;

        foreach ($ratePlans as $ratePlan) {
            $result = ($this->removeChannelRatePlan)($admin, $ratePlan);

            if (! $result->ok) {
                $message = $removed === 0
                    ? $result->message
                    : __(':removed of :total rate plans removed. :reason', ['removed' => $removed, 'total' => $ratePlans->count(), 'reason' => $result->message]);

                return new ChannelActionResult(false, $message, $result->retryable);
            }

            $removed++;
        }

        return ChannelActionResult::ok(trans_choice(
            '{1} 1 rate plan of :name removed from Channex.|[2,*] :count rate plans of :name removed from Channex.',
            $removed,
            ['name' => $name, 'count' => $removed],
        ));
    }
}
