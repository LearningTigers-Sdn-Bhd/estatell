<?php

namespace App\Services\Channels;

use App\Enums\ChannelProvider;
use App\Models\ChannelConnection;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

/**
 * Reads the OTA's rooms and rates for a connection, through the channel manager. It writes nothing.
 */
class ChannelOtaCatalogueLoader
{
    public function __construct(private ChannelDriverManager $drivers) {}

    public function __invoke(ChannelConnection $connection): ChannelOtaCatalogue
    {
        $connection->loadMissing('otaChannel');
        $ota = $connection->otaChannel;

        try {
            $details = $this->drivers->forProvider(ChannelProvider::Channex)->mappingDetails((string) $ota->channex_code, $connection->settings);
        } catch (ChannexRetryLater) {
            return new ChannelOtaCatalogue(ChannelActionResult::failedForNow(__('Channex is busy or not reachable. Try again in a minute.')));
        } catch (ChannexRequestFailed) {
            return new ChannelOtaCatalogue(ChannelActionResult::failed(__(':channel did not give its rooms and rates. Check the hotel ID and that Channex is the provider in the extranet.', ['channel' => $ota->name])));
        }

        return new ChannelOtaCatalogue(
            ChannelActionResult::ok(__('Rooms and rates fetched from :channel.', ['channel' => $ota->name])),
            (string) ($details['pricing_type'] ?? ''),
            array_values($details['rooms'] ?? []),
        );
    }
}
