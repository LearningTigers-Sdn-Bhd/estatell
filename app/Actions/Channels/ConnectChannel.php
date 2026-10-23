<?php

namespace App\Actions\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelProvider;
use App\Models\ChannelConnection;
use App\Models\ChannelProperty;
use App\Models\OtaChannel;
use App\Models\User;
use App\Services\Channels\ChannelActionResult;
use App\Services\Channels\ChannelConnectionChecker;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\ChannelHotelId;
use App\Services\Channels\Channex\ChannexChannelMapper;
use App\Services\Channels\Channex\ChannexRequestFailed;
use App\Services\Channels\Channex\ChannexRetryLater;

class ConnectChannel
{
    public function __construct(private ChannelDriverManager $drivers, private ChannelConnectionChecker $checker, private ChannexChannelMapper $mapper) {}

    /**
     * Test the OTA settings, create the connection at Channex (disabled, no rate mapping yet) and save it as a draft.
     * Channex allows one connection per hotel ID, so a hotel ID used elsewhere is refused there and nothing is saved.
     *
     * @param  array<string, mixed>  $input
     */
    public function __invoke(User $admin, ChannelProperty $channelProperty, OtaChannel $ota, array $input): ChannelActionResult
    {
        if ($channelProperty->connections()->where('ota_channel_id', $ota->id)->exists()) {
            return ChannelActionResult::failed(__(':channel is already connected for this property.', ['channel' => $ota->name]));
        }

        $check = ($this->checker)($ota, $input);

        if (! $check->result->ok) {
            return $check->result;
        }

        $channelProperty->loadMissing('property');
        $payload = $this->mapper->createPayload(
            (string) $ota->channex_code,
            "{$ota->name} - {$channelProperty->property->name}",
            (string) config('services.channex.group_id'),
            $channelProperty->external_property_id,
            $check->settings,
        );

        try {
            $channel = $this->drivers->forProvider(ChannelProvider::Channex)->createChannel($payload);
        } catch (ChannexRetryLater) {
            return ChannelActionResult::failedForNow(__('Channex is busy or not reachable. Try again in a minute.'));
        } catch (ChannexRequestFailed) {
            return ChannelActionResult::failed(__('Channex refused the :channel connection. The hotel ID may already be connected to another property.', ['channel' => $ota->name]));
        }

        $connection = ChannelConnection::query()->create([
            'channel_property_id' => $channelProperty->id,
            'ota_channel_id' => $ota->id,
            'external_channel_id' => $channel['id'],
            'settings' => $check->settings,
            'status' => ChannelConnectionStatus::Draft,
        ]);

        activity()
            ->performedOn($channelProperty->property)
            ->causedBy($admin)
            ->event('channel.connected')
            ->withProperties(['ota_channel_id' => $ota->id, 'connection_id' => $connection->id])
            ->log('OTA connected through Channex');

        return ChannelActionResult::ok(__(':channel connected to hotel :hotel.', ['channel' => $ota->name, 'hotel' => ChannelHotelId::from($check->settings)]));
    }
}
