<?php

namespace App\Actions\Channels;

use App\Enums\ChannelProvider;
use App\Models\ChannelRatePlanMapping;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

class RemoveChannelRatePlan
{
    public function __construct(private ChannelDriverManager $drivers) {}

    /**
     * Remove one rate plan from Channex and forget its link. Channex refuses while an OTA uses it, and then nothing changes.
     */
    public function __invoke(User $admin, ChannelRatePlanMapping $mapping): ChannelActionResult
    {
        $mapping->loadMissing('ratePlan', 'roomType');
        $name = "{$mapping->ratePlan->name} · {$mapping->roomType->name}";

        try {
            $this->drivers->forProvider(ChannelProvider::Channex)->removeRatePlan($mapping->external_rate_plan_id);
        } catch (ChannexRetryLater) {
            return ChannelActionResult::failed(__('Channex is busy or not reachable. Try again in a minute.'));
        } catch (ChannexRequestFailed) {
            return ChannelActionResult::failed(__(':name could not be removed. An OTA may still use it.', ['name' => $name]));
        }

        $mapping->delete();

        activity()
            ->performedOn($mapping->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.rate_plan_removed')
            ->withProperties(['rate_plan_id' => $mapping->rate_plan_id, 'room_type_id' => $mapping->room_type_id])
            ->log('Rate plan removed from Channex');

        return ChannelActionResult::ok(__(':name removed from Channex.', ['name' => $name]));
    }
}
