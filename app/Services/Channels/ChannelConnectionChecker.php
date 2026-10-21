<?php

namespace App\Services\Channels;

use App\Enums\ChannelProvider;
use App\Models\OtaChannel;
use App\Services\Channels\Channex\ChannexChannelMapper;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

/**
 * Asks the OTA, through the channel manager, whether it knows the hotel ID and accepts the provider. It writes nothing.
 */
class ChannelConnectionChecker
{
    public function __construct(private ChannelDriverManager $drivers, private ChannexChannelMapper $mapper) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function __invoke(OtaChannel $ota, array $input): ChannelConnectionCheck
    {
        if ($ota->channex_code === null || ! $ota->is_active) {
            return new ChannelConnectionCheck(ChannelActionResult::failed(__(':channel has no Channex adapter. Connect it in Channex by hand.', ['channel' => $ota->name])));
        }

        $driver = $this->drivers->forProvider(ChannelProvider::Channex);

        try {
            $settings = $this->mapper->settings($driver->adapter($ota->channex_code), $input);

            if (blank($settings['hotel_id'] ?? null)) {
                return new ChannelConnectionCheck(ChannelActionResult::failed(__('Enter the :channel hotel ID first.', ['channel' => $ota->name])));
            }

            $test = $driver->testConnection($ota->channex_code, $settings);
        } catch (ChannexRetryLater) {
            return new ChannelConnectionCheck(ChannelActionResult::failedForNow(__('Channex is busy or not reachable. Try again in a minute.')));
        } catch (ChannexRequestFailed) {
            return new ChannelConnectionCheck(ChannelActionResult::failed(__('Channex refused the :channel settings. Check the hotel ID, then test again.', ['channel' => $ota->name])));
        }

        if (! $test['success']) {
            return new ChannelConnectionCheck(ChannelActionResult::failed(__(':channel refused the connection. Choose Channex as the provider in the extranet, check the hotel ID, then test again.', ['channel' => $ota->name])));
        }

        return new ChannelConnectionCheck(ChannelActionResult::ok(__(':channel sees Channex as the provider for hotel :hotel.', ['channel' => $ota->name, 'hotel' => $settings['hotel_id']])), $settings);
    }
}
