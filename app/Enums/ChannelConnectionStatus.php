<?php

namespace App\Enums;

/**
 * Where one OTA connection stands. Draft is connected at Channex with no rate mapping yet; Mapped can be activated.
 */
enum ChannelConnectionStatus: string
{
    case Draft = 'draft';
    case Mapped = 'mapped';
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Mapped => __('Mapped'),
            self::Active => __('Active'),
            self::Paused => __('Paused'),
            self::Error => __('Error'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Draft => Tone::Neutral,
            self::Mapped => Tone::Info,
            self::Active => Tone::Success,
            self::Paused => Tone::Warning,
            self::Error => Tone::Danger,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::Mapped, self::Error], true),
            self::Mapped => in_array($next, [self::Draft, self::Active, self::Error], true),
            self::Active => in_array($next, [self::Paused, self::Error], true),
            self::Paused => in_array($next, [self::Active, self::Mapped, self::Error], true),
            self::Error => $next !== self::Error,
        };
    }
}
