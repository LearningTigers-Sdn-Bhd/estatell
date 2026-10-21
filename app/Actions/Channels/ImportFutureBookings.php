<?php

namespace App\Actions\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelConnection;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\ChannelOtaOverview;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

class ImportFutureBookings
{
    public function __construct(private ChannelDriverManager $drivers) {}

    /**
     * Ask the OTA for the bookings it already holds. Channex sends each one as a booking revision, and the normal booking
     * ingestion saves it. Only an OTA whose adapter has the action, and only a connection that is running.
     */
    public function __invoke(User $admin, ChannelConnection $connection): ChannelActionResult
    {
        $connection->loadMissing('otaChannel', 'channelProperty.property');
        $name = $connection->otaChannel->name;

        if (! ChannelOtaOverview::canImportFutureBookings($connection->otaChannel)) {
            return ChannelActionResult::failed(__(':channel cannot import bookings that already exist.', ['channel' => $name]));
        }

        if ($connection->status !== ChannelConnectionStatus::Active) {
            return ChannelActionResult::failed(__('Start :channel first, then import its bookings.', ['channel' => $name]));
        }

        try {
            $this->drivers->forProvider(ChannelProvider::Channex)->loadFutureReservations((string) $connection->external_channel_id);
        } catch (ChannexRetryLater|ChannexRequestFailed $exception) {
            return ChannelActionResult::fromProviderFailure($exception, __('Channex could not load the future bookings of :channel.', ['channel' => $name]));
        }

        activity()
            ->performedOn($connection->channelProperty->property)
            ->causedBy($admin)
            ->event('channel.bookings_imported')
            ->withProperties(['connection_id' => $connection->id])
            ->log('Future OTA bookings requested through Channex');

        return ChannelActionResult::ok(__('Asked :channel for its future bookings. They arrive in a few minutes.', ['channel' => $name]));
    }
}
