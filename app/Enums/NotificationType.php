<?php

namespace App\Enums;

enum NotificationType: string
{
    case NightAuditCompleted = 'night_audit_completed';
    case NightAuditExceptions = 'night_audit_exceptions';
    case NightAuditFailed = 'night_audit_failed';
    case RegistrationCardLink = 'registration_card_link';
    case OnboardingReminder = 'onboarding_reminder';
    case DormStrategyChanged = 'dorm_strategy_changed';
    case SupportAccessUsed = 'support_access_used';
    case GoLiveApproved = 'go_live_approved';
    case GoLiveRejected = 'go_live_rejected';
    case PortalBookingCreated = 'portal_booking_created';
    case PortalBookingCancelled = 'portal_booking_cancelled';
    case PortalCancellationRequested = 'portal_cancellation_requested';
    case ChannelBookingReceived = 'channel_booking_received';
    case ChannelBookingCancelled = 'channel_booking_cancelled';
    case ChannelRemovalBlocked = 'channel_removal_blocked';

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
        };
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::RegistrationCardLink => [NotificationChannel::Mail],
            self::SupportAccessUsed => [NotificationChannel::Database],
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
            self::NightAuditFailed, self::SupportAccessUsed, self::GoLiveApproved, self::GoLiveRejected, self::PortalCancellationRequested, self::ChannelRemovalBlocked => $channel === NotificationChannel::Mail,
            default => true,
        };
    }

    public function recipientPermission(): ?string
    {
        return match ($this) {
            self::NightAuditCompleted, self::NightAuditExceptions => 'night-audit.receive-report',
            self::NightAuditFailed => 'night-audit.run',
            self::PortalBookingCreated, self::PortalBookingCancelled, self::PortalCancellationRequested, self::ChannelBookingReceived, self::ChannelBookingCancelled => 'reservations.manage',
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
