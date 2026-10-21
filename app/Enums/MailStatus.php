<?php

namespace App\Enums;

enum MailStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Sent'),
            self::Failed => __('Failed'),
            self::Suppressed => __('Suppressed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Sent => Tone::Success,
            self::Failed => Tone::Danger,
            self::Suppressed => Tone::Warning,
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
