<?php

namespace App\Enums;

enum NotificationType: string
{
    case NightAuditCompleted = 'night_audit_completed';
    case NightAuditExceptions = 'night_audit_exceptions';
    case NightAuditFailed = 'night_audit_failed';
    case ChannelBookingReceived = 'channel_booking_received';
    case ChannelBookingCancelled = 'channel_booking_cancelled';
    case ChannelRemovalBlocked = 'channel_removal_blocked';
    case OnlineBookingReceived = 'online_booking_received';
    case OnlineBookingCancelled = 'online_booking_cancelled';
    case OnlineRefundNeedsAttention = 'online_refund_needs_attention';
    case PortalBookingCreated = 'portal_booking_created';
    case PortalBookingCancelled = 'portal_booking_cancelled';
    case PortalCancellationRequested = 'portal_cancellation_requested';
    case PaymentSlipReceived = 'payment_slip_received';
    case RegistrationCardLink = 'registration_card_link';
    case AiThreadEscalated = 'ai_thread_escalated';
    case AiCapNearlyReached = 'ai_cap_nearly_reached';
    case OnboardingReminder = 'onboarding_reminder';
    case GoLiveApproved = 'go_live_approved';
    case GoLiveRejected = 'go_live_rejected';
    case SupportAccessUsed = 'support_access_used';
    case DormStrategyChanged = 'dorm_strategy_changed';
    case NearbyPlaceRemoved = 'nearby_place_removed';

    public function label(): string
    {
        return match ($this) {
            self::NightAuditCompleted => __('Night audit completed'),
            self::NightAuditExceptions => __('Night audit exceptions'),
            self::NightAuditFailed => __('Night audit failed'),
            self::RegistrationCardLink => __('Registration card link'),
            self::OnboardingReminder => __('Onboarding reminder'),
            self::DormStrategyChanged => __('Dorm strategy changed'),
            self::SupportAccessUsed => __('Money correction by support'),
            self::GoLiveApproved => __('Go-live approved'),
            self::GoLiveRejected => __('Go-live sent back'),
            self::PortalBookingCreated => __('Company portal booking'),
            self::PortalBookingCancelled => __('Company portal cancellation'),
            self::PortalCancellationRequested => __('Company cancellation request'),
            self::ChannelBookingReceived => __('Channel booking received'),
            self::ChannelBookingCancelled => __('Channel booking cancelled'),
            self::ChannelRemovalBlocked => __('Archive blocked by a channel'),
            self::PaymentSlipReceived => __('Company payment slip'),
            self::NearbyPlaceRemoved => __('Nearby place removed'),
            self::OnlineBookingReceived => __('Online booking received'),
            self::OnlineRefundNeedsAttention => __('Online refund needs attention'),
            self::OnlineBookingCancelled => __('Online booking cancelled'),
            self::AiThreadEscalated => __('Chat handed over'),
            self::AiCapNearlyReached => __('AI assistant near its monthly cap'),
        };
    }

    /**
     * The heading it is listed under on the notification settings pages. Cases are declared in group order.
     */
    public function group(): string
    {
        return match ($this) {
            self::NightAuditCompleted, self::NightAuditExceptions, self::NightAuditFailed => __('Night audit'),
            self::ChannelBookingReceived, self::ChannelBookingCancelled, self::ChannelRemovalBlocked => __('Channel manager'),
            self::OnlineBookingReceived, self::OnlineBookingCancelled, self::OnlineRefundNeedsAttention => __('Booking website'),
            self::PortalBookingCreated, self::PortalBookingCancelled, self::PortalCancellationRequested, self::PaymentSlipReceived => __('Company portal'),
            self::RegistrationCardLink => __('Guests'),
            self::AiThreadEscalated, self::AiCapNearlyReached => __('AI assistant'),
            self::OnboardingReminder, self::GoLiveApproved, self::GoLiveRejected, self::SupportAccessUsed => __('Account and setup'),
            self::DormStrategyChanged, self::NearbyPlaceRemoved => __('Property'),
        };
    }

    /**
     * One line on when it is sent and who gets it, for the notification settings pages.
     */
    public function description(): string
    {
        return match ($this) {
            self::NightAuditCompleted => __('The night audit closed the day. To staff who receive the audit report.'),
            self::NightAuditExceptions => __('The night audit found items to check. To staff who receive the audit report.'),
            self::NightAuditFailed => __('The night audit stopped with an error. To staff who can run the audit.'),
            self::ChannelBookingReceived => __('A booking came in from an OTA. To staff who manage reservations.'),
            self::ChannelBookingCancelled => __('An OTA booking was cancelled. To staff who manage reservations.'),
            self::ChannelRemovalBlocked => __('An archive was stopped because an OTA still uses it. To the staff member who archived.'),
            self::OnlineBookingReceived => __('A guest booked on your booking website. To staff who manage reservations.'),
            self::OnlineBookingCancelled => __('A guest cancelled on your booking website. To staff who manage reservations.'),
            self::OnlineRefundNeedsAttention => __('An online refund failed or a late payment was refunded. To staff who can refund.'),
            self::PortalBookingCreated => __('A company booked through its portal. To staff who manage reservations.'),
            self::PortalBookingCancelled => __('A company cancelled a portal booking. To staff who manage reservations.'),
            self::PortalCancellationRequested => __('A company asked to cancel a booking. To staff who manage reservations.'),
            self::PaymentSlipReceived => __('A company uploaded a payment slip. To staff who record city ledger payments.'),
            self::RegistrationCardLink => __('The link to fill in the registration card. To the guest.'),
            self::AiThreadEscalated => __('The AI assistant handed a chat to staff. To front desk staff.'),
            self::AiCapNearlyReached => __('AI assistant spend reached 80% of the monthly cap. To staff who manage settings.'),
            self::OnboardingReminder => __('Setup steps are still open. To the owner.'),
            self::GoLiveApproved => __('The property was approved to go live. To the owner.'),
            self::GoLiveRejected => __('The go-live request was sent back with notes. To the owner.'),
            self::SupportAccessUsed => __('Support made a money correction while viewing the property. To the owner.'),
            self::DormStrategyChanged => __('The dorm bed strategy was changed. To the owner.'),
            self::NearbyPlaceRemoved => __('A nearby place was removed from your booking website. To the owner.'),
        };
    }

    /**
     * Lucide icon name for the notification settings pages.
     */
    public function icon(): string
    {
        return match ($this) {
            self::NightAuditCompleted => 'circle-check',
            self::NightAuditExceptions => 'triangle-alert',
            self::NightAuditFailed => 'circle-x',
            self::ChannelBookingReceived, self::OnlineBookingReceived, self::PortalBookingCreated => 'calendar-plus',
            self::ChannelBookingCancelled, self::OnlineBookingCancelled, self::PortalBookingCancelled => 'calendar-x',
            self::ChannelRemovalBlocked => 'archive-x',
            self::OnlineRefundNeedsAttention => 'banknote-x',
            self::PortalCancellationRequested => 'calendar-clock',
            self::PaymentSlipReceived => 'receipt',
            self::RegistrationCardLink => 'id-card',
            self::AiThreadEscalated => 'messages-square',
            self::AiCapNearlyReached => 'gauge',
            self::OnboardingReminder => 'list-checks',
            self::GoLiveApproved => 'rocket',
            self::GoLiveRejected => 'undo-2',
            self::SupportAccessUsed => 'life-buoy',
            self::DormStrategyChanged => 'bed-double',
            self::NearbyPlaceRemoved => 'map-pin-off',
        };
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::RegistrationCardLink => [NotificationChannel::Mail],
            self::SupportAccessUsed, self::NearbyPlaceRemoved => [NotificationChannel::Database],
            default => [NotificationChannel::Mail, NotificationChannel::Database],
        };
    }

    public function defaultEnabled(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->channels(), true);
    }

    public function canOptOut(NotificationChannel $channel): bool
    {
        if (! $this->defaultEnabled($channel)) {
            return false;
        }

        return match ($this) {
            self::RegistrationCardLink => false,
            self::NightAuditFailed, self::SupportAccessUsed, self::NearbyPlaceRemoved, self::GoLiveApproved, self::GoLiveRejected, self::PortalCancellationRequested, self::ChannelRemovalBlocked => $channel === NotificationChannel::Mail,
            default => true,
        };
    }

    public function recipientPermission(): ?string
    {
        return match ($this) {
            self::NightAuditCompleted, self::NightAuditExceptions => 'night-audit.receive-report',
            self::NightAuditFailed => 'night-audit.run',
            self::PaymentSlipReceived => 'city-ledger.payment',
            self::PortalBookingCreated, self::PortalBookingCancelled, self::PortalCancellationRequested, self::ChannelBookingReceived, self::ChannelBookingCancelled, self::OnlineBookingReceived, self::OnlineBookingCancelled => 'reservations.manage',
            self::OnlineRefundNeedsAttention => 'payment.refund',
            self::AiThreadEscalated => 'front-desk.view',
            self::AiCapNearlyReached => 'settings.manage',
            default => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
