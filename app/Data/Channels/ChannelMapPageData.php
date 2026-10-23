<?php

namespace App\Data\Channels;

use App\Models\ChannelConnection;
use App\Services\Channels\ChannelHotelId;
use App\Services\Channels\ChannelOtaCatalogue;
use Spatie\LaravelData\Data;

/**
 * The map page (connect step 2). `usesPrimary` and `usesType` come from the adapter's `rate_params`: Booking.com, Agoda
 * and Expedia mark one primary occupancy per OTA rate, Hostelworld asks for a type instead.
 */
class ChannelMapPageData extends Data
{
    /**
     * @param  list<ChannelMapRowData>  $rows
     * @param  list<ChannelEstatellOptionData>  $options
     */
    public function __construct(
        public int $connectionId,
        public string $otaName,
        public ?string $hotelId,
        public bool $usesPrimary,
        public bool $usesType,
        public string $pricingType,
        public array $rows,
        public array $options,
        public int $notOnChannels,
    ) {}

    /**
     * Expects `otaChannel` loaded.
     *
     * @param  array<string, mixed>  $adapter
     * @param  list<ChannelMapRowData>  $rows
     * @param  list<ChannelEstatellOptionData>  $options
     */
    public static function forConnection(ChannelConnection $connection, ChannelOtaCatalogue $catalogue, array $adapter, array $rows, array $options, int $notOnChannels): self
    {
        $params = $adapter['rate_params'] ?? [];

        return new self(
            connectionId: $connection->id,
            otaName: $connection->otaChannel->name,
            hotelId: ChannelHotelId::from($connection->settings ?? []),
            usesPrimary: array_key_exists('primary_occ', $params),
            usesType: array_key_exists('type', $params),
            pricingType: $catalogue->pricingType,
            rows: $rows,
            options: $options,
            notOnChannels: $notOnChannels,
        );
    }
}
