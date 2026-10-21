<?php

namespace App\Enums;

/**
 * How well a room type and rate plan pair fits a channel manager. Flattened pairs are sent in a simpler form.
 */
enum ChannelSupport: string
{
    case Full = 'full';
    case Flattened = 'flattened';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Full => __('Full'),
            self::Flattened => __('Flattened'),
            self::Unsupported => __('Not sent'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Full => Tone::Success,
            self::Flattened => Tone::Info,
            self::Unsupported => Tone::Neutral,
        };
    }

    /**
     * Whether pairs with this result are sent to the channel manager.
     */
    public function isSent(): bool
    {
        return $this !== self::Unsupported;
    }
}
