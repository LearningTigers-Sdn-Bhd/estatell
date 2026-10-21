<?php

namespace App\Actions\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelBookingRevision;
use App\Models\ChannelConnection;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;
use Illuminate\Support\Facades\DB;

class DisconnectChannel
{
    public function __construct(private ChannelDriverManager $drivers) {}

    /**
     * Remove the connection at Channex (stopping it first when it is running) and forget it here with its mapping. Bookings
     * it brought stay, with no connection. The hotel ID is free for another connection afterwards. Nothing is forgotten here
     * unless Channex is done with it.
     */
    public function __invoke(User $admin, ChannelConnection $connection): ChannelActionResult
    {
        $connection->loadMissing('otaChannel', 'channelProperty.property');
        $name = $connection->otaChannel->name;
        $driver = $this->drivers->forProvider(ChannelProvider::Channex);

        try {
            if ($connection->external_channel_id !== null) {
                if ($connection->status === ChannelConnectionStatus::Active) {
                    $driver->deactivate($connection->external_channel_id);
                }

                $driver->deleteChannel($connection->external_channel_id);
            }
        } catch (ChannexRetryLater|ChannexRequestFailed $exception) {
            return ChannelActionResult::fromProviderFailure($exception, __('Channex refused to remove :channel.', ['channel' => $name]));
        }

        DB::transaction(function () use ($connection): void {
            ChannelBookingRevision::query()->where('channel_connection_id', $connection->id)->update(['channel_connection_id' => null]);
            $connection->delete();
        });

        activity()
            ->performedOn($connection->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.disconnected')
            ->withProperties(['ota_channel_id' => $connection->ota_channel_id, 'connection_id' => $connection->id])
            ->log('OTA disconnected from Channex');

        return ChannelActionResult::ok(__(':channel disconnected.', ['channel' => $name]));
    }
}
