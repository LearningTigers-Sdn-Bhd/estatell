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

    public function label(): string
    {
        return match ($this) {
            self::NightAuditCompleted => __('Night audit completed'),
            self::NightAuditExceptions => __('Night audit exceptions'),
            self::NightAuditFailed => __('Night audit failed'),
            self::RegistrationCardLink => __('Registration card link'),
            self::OnboardingReminder => __('Onboarding reminder'),
            self::DormStrategyChanged => __('Dorm strategy changed'),
            self::SupportAccessUsed => __('Support access used'),
        };
    }

    /**
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::RegistrationCardLink => [NotificationChannel::Mail],
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
            self::NightAuditFailed, self::SupportAccessUsed => $channel === NotificationChannel::Mail,
            default => true,
        };
    }

    public function recipientPermission(): ?string
    {
        return match ($this) {
            self::NightAuditCompleted, self::NightAuditExceptions => 'night-audit.receive-report',
            self::NightAuditFailed => 'night-audit.run',
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
