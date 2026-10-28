<?php

namespace App\Enums;

/**
 * The Channex webhook events that show as alerts on the admin Channels tab, and the problems estatell finds itself
 * (a structure sync or removal that failed, an OTA collected payment that could not be posted).
 */
enum ChannelAlertEvent: string
{
    case NonAckedBooking = 'non_acked_booking';
    case BookingUnmappedRoom = 'booking_unmapped_room';
    case BookingUnmappedRate = 'booking_unmapped_rate';
    case SyncError = 'sync_error';
    case RateError = 'rate_error';
    case StructureSyncFailed = 'structure_sync_failed';
    case RemovalBlocked = 'removal_blocked';
    case OtaPaymentNotPosted = 'ota_payment_not_posted';

    public function label(): string
    {
        return match ($this) {
            self::NonAckedBooking => __('Not acknowledged booking'),
            self::BookingUnmappedRoom => __('Unmapped room'),
            self::BookingUnmappedRate => __('Unmapped rate'),
            self::SyncError => __('Sync error'),
            self::RateError => __('Rate error'),
            self::StructureSyncFailed => __('Structure sync failed'),
            self::RemovalBlocked => __('Removal blocked'),
            self::OtaPaymentNotPosted => __('OTA payment not posted'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::NonAckedBooking => Tone::Warning,
            self::BookingUnmappedRoom, self::BookingUnmappedRate, self::SyncError, self::RateError, self::StructureSyncFailed => Tone::Danger,
            self::RemovalBlocked, self::OtaPaymentNotPosted => Tone::Warning,
        };
    }
}
