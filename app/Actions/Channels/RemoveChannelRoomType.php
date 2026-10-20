<?php

namespace App\Actions\Channels;

use App\Enums\ChannelProvider;
use App\Models\ChannelRatePlanMapping;
use App\Models\ChannelRoomTypeMapping;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

class RemoveChannelRoomType
{
    public function __construct(private ChannelDriverManager $drivers) {}

    /**
     * Remove one room type from Channex and forget its link. Its rate plans must be removed first. Channex refuses while an
     * OTA uses it, and then nothing changes.
     */
    public function __invoke(User $admin, ChannelRoomTypeMapping $mapping): ChannelActionResult
    {
        $mapping->loadMissing('roomType');
        $name = $mapping->roomType->name;

        if (ChannelRatePlanMapping::query()->where('channel_property_id', $mapping->channel_property_id)->where('room_type_id', $mapping->room_type_id)->exists()) {
            return ChannelActionResult::failed(__('Remove the rate plans of :name first.', ['name' => $name]));
        }

        try {
            $this->drivers->forProvider(ChannelProvider::Channex)->removeRoomType($mapping->external_room_type_id);
        } catch (ChannexRetryLater) {
            return ChannelActionResult::failed(__('Channex is busy or not reachable. Try again in a minute.'));
        } catch (ChannexRequestFailed) {
            return ChannelActionResult::failed(__(':name could not be removed. An OTA may still use it.', ['name' => $name]));
        }

        $mapping->delete();

        activity()
            ->performedOn($mapping->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.room_type_removed')
            ->withProperties(['room_type_id' => $mapping->room_type_id])
            ->log('Room type removed from Channex');

        return ChannelActionResult::ok(__(':name removed from Channex.', ['name' => $name]));
    }
}
