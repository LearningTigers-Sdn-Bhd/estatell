<?php

namespace App\Services\Channels;

/**
 * The connection setting that names the hotel on the OTA. Channex adapters call it `hotel_id` (Booking.com, Agoda,
 * Expedia, Hostelworld) or `hotel_code` (Trip.com, `CTrip`). Exactly one of them is required on the Connect form.
 */
class ChannelHotelId
{
    /** @var list<string> */
    public const KEYS = ['hotel_id', 'hotel_code'];

    public static function isKey(string $param): bool
    {
        return in_array($param, self::KEYS, true);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function from(array $settings): ?string
    {
        foreach (self::KEYS as $key) {
            if (filled($settings[$key] ?? null)) {
                return trim((string) $settings[$key]);
            }
        }

        return null;
    }
}
