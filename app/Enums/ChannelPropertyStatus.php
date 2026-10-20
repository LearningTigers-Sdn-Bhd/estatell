<?php

namespace App\Enums;

/**
 * Where a property stands with its channel manager. A property with no `channel_properties` row is NotSynced.
 */
enum ChannelPropertyStatus: string
{
    case NotSynced = 'not_synced';
    case Synced = 'synced';
    case Paused = 'paused';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::NotSynced => __('Not synced'),
            self::Synced => __('Synced'),
            self::Paused => __('Paused'),
            self::Error => __('Error'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::NotSynced => Tone::Neutral,
            self::Synced => Tone::Success,
            self::Paused => Tone::Warning,
            self::Error => Tone::Danger,
        };
    }
}
