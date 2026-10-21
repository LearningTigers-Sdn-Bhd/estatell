<?php

namespace App\Enums;

enum MailStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Sent'),
            self::Delivered => __('Delivered'),
            self::Bounced => __('Bounced'),
            self::Complained => __('Complained'),
            self::Failed => __('Failed'),
            self::Suppressed => __('Suppressed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Sent => Tone::Info,
            self::Delivered => Tone::Success,
            self::Bounced, self::Failed => Tone::Danger,
            self::Complained => Tone::Warning,
            self::Suppressed => Tone::Warning,
        };
    }

    /**
     * What a delivery webhook may change. A repeated or late event changes nothing.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Sent => $status !== self::Sent,
            self::Delivered => in_array($status, [self::Bounced, self::Complained], true),
            default => false,
        };
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
