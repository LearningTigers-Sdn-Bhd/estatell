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

class PauseChannel
{
    public function __construct(private ChannelDriverManager $drivers) {}

    /**
     * Stop sending to the OTA and stop collecting its bookings. Channex keeps the connection and its mapping, and the admin
     * starts it again with Activate.
     */
    public function __invoke(User $admin, ChannelConnection $connection): ChannelActionResult
    {
        $connection->loadMissing('otaChannel', 'channelProperty.property');
        $name = $connection->otaChannel->name;

        if ($connection->status !== ChannelConnectionStatus::Active) {
            return ChannelActionResult::failed(__(':channel is not active.', ['channel' => $name]));
        }

        try {
            $this->drivers->forProvider(ChannelProvider::Channex)->deactivate((string) $connection->external_channel_id);
        } catch (ChannexRetryLater|ChannexRequestFailed $exception) {
            return ChannelActionResult::fromProviderFailure($exception, __('Channex refused to pause :channel.', ['channel' => $name]));
        }

        $connection->update(['status' => ChannelConnectionStatus::Paused]);

        activity()
            ->performedOn($connection->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.paused')
            ->withProperties(['connection_id' => $connection->id])
            ->log('OTA paused through Channex');

        return ChannelActionResult::ok(__(':channel is paused. Nothing is sent to it until you start it again.', ['channel' => $name]));
    }
}
