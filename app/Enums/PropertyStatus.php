<?php

namespace App\Enums;

enum PropertyStatus: string
{
    case Onboarding = 'onboarding';
    case PendingApproval = 'pending_approval';
    case Live = 'live';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => __('Onboarding'),
            self::PendingApproval => __('Pending approval'),
            self::Live => __('Live'),
            self::Suspended => __('Suspended'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Onboarding => Tone::Info,
            self::PendingApproval => Tone::Warning,
            self::Live => Tone::Success,
            self::Suspended => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Onboarding => [self::PendingApproval, self::Live, self::Suspended],
            self::PendingApproval => [self::Onboarding, self::Live, self::Suspended],
            self::Live => [self::Suspended],
            self::Suspended => [self::Onboarding, self::PendingApproval, self::Live],
        }, true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
