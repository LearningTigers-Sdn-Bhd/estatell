<?php

namespace App\Services\Channels;

use App\Data\Channels\ChannelOtaRowData;
use App\Data\Shared\BadgeData;
use App\Enums\ChannelConnectionStatus;
use App\Models\ChannelConnection;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\PropertyOtaCredential;

/**
 * The OTAs card of the admin Channels tab: every active channel with a Channex adapter, plus the ones the owner or the
 * team already has a login or a connection for. Reads only.
 */
class ChannelOtaOverview
{
    /** The adapters that list `load_future_reservations` among their actions. */
    private const FUTURE_BOOKING_CODES = ['BookingCom', 'Expedia'];

    /**
     * Whether the OTA's adapter can load the bookings it already holds.
     */
    public static function canImportFutureBookings(OtaChannel $channel): bool
    {
        return in_array($channel->channex_code, self::FUTURE_BOOKING_CODES, true);
    }

    /**
     * @return list<ChannelOtaRowData>
     */
    public function forProperty(Property $property): array
    {
        $connections = ChannelConnection::query()
            ->whereHas('channelProperty', fn ($query) => $query->where('property_id', $property->id))
            ->get()
            ->keyBy('ota_channel_id');
        $credentials = $property->otaCredentials()->get()->keyBy('ota_channel_id');
        $rows = [];

        foreach (OtaChannel::query()->active()->where('is_other', false)->orderBy('sort_order')->get() as $channel) {
            /** @var ChannelConnection|null $connection */
            $connection = $connections->get($channel->id);
            /** @var PropertyOtaCredential|null $credential */
            $credential = $credentials->get($channel->id);

            if ($channel->channex_code === null && $connection === null && $credential === null) {
                continue;
            }

            $rows[] = new ChannelOtaRowData(
                otaChannelId: $channel->id,
                name: $channel->name,
                hotelId: ($connection === null ? null : ChannelHotelId::from($connection->settings ?? [])) ?? $credential?->property_code,
                status: $connection === null ? null : BadgeData::fromChannelConnectionStatus($connection->status),
                connectionId: $connection?->id,
                canConnect: $connection === null && $this->isSupported($channel),
                note: $connection === null ? $this->note($channel) : null,
                lastError: $connection?->last_error,
                canActivate: $connection?->status === ChannelConnectionStatus::Mapped,
                canPause: $connection?->status === ChannelConnectionStatus::Active,
                canResume: $connection?->status === ChannelConnectionStatus::Paused,
                canImport: $connection?->status === ChannelConnectionStatus::Active && self::canImportFutureBookings($channel),
            );
        }

        return $rows;
    }

    private function isSupported(OtaChannel $channel): bool
    {
        return $channel->channex_code !== null && $channel->code !== 'airbnb';
    }

    private function note(OtaChannel $channel): ?string
    {
        return match (true) {
            $channel->code === 'airbnb' => __('Connect in Channex by hand (coming later)'),
            $channel->channex_code === null => __('No Channex adapter. Connect in Channex by hand.'),
            default => null,
        };
    }
}
