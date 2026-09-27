<?php

namespace App\Enums;

use App\Features\ChannelManager;

/**
 * Per-property features that platform admins turn on or off. Each case maps to a Pennant feature class.
 */
enum PlatformFeature: string
{
    case ChannelManager = 'channel-manager';

    public function label(): string
    {
        return match ($this) {
            self::ChannelManager => __('Channel manager'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChannelManager => __('Connect this property to Channex and sync OTAs.'),
        };
    }

    /**
     * @return class-string
     */
    public function featureClass(): string
    {
        return match ($this) {
            self::ChannelManager => ChannelManager::class,
        };
    }
}
