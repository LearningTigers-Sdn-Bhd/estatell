<?php

namespace App\Actions\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelConnection;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

class ActivateChannel
{
    public function __construct(private ChannelDriverManager $drivers) {}

    /**
     * Start the connection, or start it again after a pause. Channex then sends the OTA the next 500 days of availability,
     * rates and restrictions and starts collecting bookings. A connection with no mapped line is refused.
     */
    public function __invoke(User $admin, ChannelConnection $connection): ChannelActionResult
    {
        $connection->loadMissing('otaChannel', 'channelProperty.property');
        $name = $connection->otaChannel->name;

        if (! $connection->status->canTransitionTo(ChannelConnectionStatus::Active)) {
            return ChannelActionResult::failed(__(':channel cannot be started now. Map its rooms and rates first.', ['channel' => $name]));
        }

        if (! $connection->otaMappings()->exists()) {
            return ChannelActionResult::failed(__('Map at least one rate before you start :channel.', ['channel' => $name]));
        }

        try {
            $this->drivers->forProvider(ChannelProvider::Channex)->activate((string) $connection->external_channel_id);
        } catch (ChannexRetryLater|ChannexRequestFailed $exception) {
            return ChannelActionResult::fromProviderFailure($exception, __('Channex refused to start :channel.', ['channel' => $name]));
        }

        $connection->update(['status' => ChannelConnectionStatus::Active, 'activated_at' => $connection->activated_at ?? now(), 'last_error' => null]);

        activity()
            ->performedOn($connection->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.activated')
            ->withProperties(['connection_id' => $connection->id])
            ->log('OTA activated through Channex');

        return ChannelActionResult::ok(__(':channel is active. Channex is sending it availability and rates.', ['channel' => $name]));
    }
}
