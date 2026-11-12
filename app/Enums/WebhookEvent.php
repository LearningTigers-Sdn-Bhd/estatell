<?php

namespace App\Enums;

/**
 * The events an outgoing webhook endpoint can hear. The value is the `event` field of the payload, so a value never
 * changes. See docs/32-webhooks/01-outgoing-webhooks.md.
 */
enum WebhookEvent: string
{
    case BookingCreated = 'booking.created';
    case BookingUpdated = 'booking.updated';
    case BookingCancelled = 'booking.cancelled';
    case BookingNoShow = 'booking.no_show';
    case StayCheckedIn = 'stay.checked_in';
    case StayCheckedOut = 'stay.checked_out';
    case PaymentReceived = 'payment.received';
    case PaymentRefunded = 'payment.refunded';
    case FolioClosed = 'folio.closed';
    case GuestCreated = 'guest.created';
    case GuestUpdated = 'guest.updated';
    case GuestMerged = 'guest.merged';
    case GuestAnonymized = 'guest.anonymized';
    case RoomCleaned = 'room.cleaned';
    case RoomMaintenanceFlagged = 'room.maintenance_flagged';
    case NightAuditCompleted = 'night_audit.completed';
    case ChannelAlert = 'channel.alert';

    public function label(): string
    {
        return match ($this) {
            self::BookingCreated => __('Booking created'),
            self::BookingUpdated => __('Booking updated'),
            self::BookingCancelled => __('Booking cancelled'),
            self::BookingNoShow => __('Booking no-show'),
            self::StayCheckedIn => __('Checked in'),
            self::StayCheckedOut => __('Checked out'),
            self::PaymentReceived => __('Payment received'),
            self::PaymentRefunded => __('Payment refunded'),
            self::FolioClosed => __('Folio closed'),
            self::GuestCreated => __('Guest created'),
            self::GuestUpdated => __('Guest updated'),
            self::GuestMerged => __('Guests merged'),
            self::GuestAnonymized => __('Guest anonymized'),
            self::RoomCleaned => __('Room cleaned'),
            self::RoomMaintenanceFlagged => __('Room needs maintenance'),
            self::NightAuditCompleted => __('Night audit completed'),
            self::ChannelAlert => __('Channel alert'),
        };
    }

    /**
     * The heading the event is listed under on the endpoint Sheet.
     */
    public function group(): string
    {
        return match ($this) {
            self::BookingCreated, self::BookingUpdated, self::BookingCancelled, self::BookingNoShow => __('Booking'),
            self::StayCheckedIn, self::StayCheckedOut => __('Stay'),
            self::PaymentReceived, self::PaymentRefunded, self::FolioClosed => __('Payment'),
            self::GuestCreated, self::GuestUpdated, self::GuestMerged, self::GuestAnonymized => __('Guest'),
            self::RoomCleaned, self::RoomMaintenanceFlagged => __('Housekeeping'),
            self::NightAuditCompleted, self::ChannelAlert => __('Operations'),
        };
    }

    /**
     * @return list<array{value: string, label: string, group: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $event): array => ['value' => $event->value, 'label' => $event->label(), 'group' => $event->group()],
            self::cases(),
        );
    }
}
