<?php

namespace App\Enums;

/**
 * Where one event's delivery to one endpoint stands. See docs/32-webhooks/01-outgoing-webhooks.md#delivery-status.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Retrying = 'retrying';
    case Delivered = 'delivered';
    case Refused = 'refused';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Retrying => __('Retrying'),
            self::Delivered => __('Delivered'),
            self::Refused => __('Refused'),
            self::Failed => __('Failed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Neutral,
            self::Retrying => Tone::Warning,
            self::Delivered => Tone::Success,
            self::Refused, self::Failed => Tone::Danger,
        };
    }

    /**
     * Pending and Retrying wait for a try. A Refused or Failed delivery is tried again only when an admin retries it.
     */
    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Pending, self::Retrying => $status !== self::Pending,
            self::Refused, self::Failed => $status === self::Pending,
            self::Delivered => false,
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Delivered, self::Refused, self::Failed], true);
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
