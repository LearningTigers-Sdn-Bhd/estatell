<?php

namespace App\Enums;

use App\Features\AccountingIntegration;
use App\Features\Boats;
use App\Features\ChannelManager;

/**
 * Per-property features that platform admins turn on or off. Each case maps to a Pennant feature class.
 */
enum PlatformFeature: string
{
    case ChannelManager = 'channel-manager';
    case Boats = 'boats';
    case AccountingIntegration = 'accounting-integration';

    public function label(): string
    {
        return match ($this) {
            self::ChannelManager => __('Channel manager'),
            self::Boats => __('Boats'),
            self::AccountingIntegration => __('Accounting integration'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChannelManager => __('Connect this property to Channex and sync OTAs.'),
            self::Boats => __('Boat departures, fares and boat trips for island and jetty properties.'),
            self::AccountingIntegration => __('Push each closed business date to the property\'s accounting software.'),
        };
    }

    /**
     * @return class-string
     */
    public function featureClass(): string
    {
        return match ($this) {
            self::ChannelManager => ChannelManager::class,
            self::Boats => Boats::class,
            self::AccountingIntegration => AccountingIntegration::class,
        };
    }
}
