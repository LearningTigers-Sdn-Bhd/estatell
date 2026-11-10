<?php

namespace App\Enums;

use App\Features\AccountingIntegration;
use App\Features\Boats;
use App\Features\BookingEngine;
use App\Features\ChannelManager;
use App\Features\DoorLocks;
use App\Features\Kiosks;

/**
 * Per-property features that platform admins turn on or off. Each case maps to a Pennant feature class.
 */
enum PlatformFeature: string
{
    case ChannelManager = 'channel-manager';
    case Boats = 'boats';
    case AccountingIntegration = 'accounting-integration';
    case DoorLocks = 'door-locks';
    case Kiosks = 'kiosks';
    case BookingEngine = 'booking-engine';

    public function label(): string
    {
        return match ($this) {
            self::ChannelManager => __('Channel manager'),
            self::Boats => __('Boats'),
            self::AccountingIntegration => __('Accounting integration'),
            self::DoorLocks => __('Door locks'),
            self::Kiosks => __('Kiosks'),
            self::BookingEngine => __('Booking engine'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChannelManager => __('Connect this property to a channel manager and sync OTAs.'),
            self::Boats => __('Boat departures, fares and boat trips for island and jetty properties.'),
            self::AccountingIntegration => __('Push each closed business date to the property\'s accounting software.'),
            self::DoorLocks => __('Door locks for each stay: TTLock PINs and cards from the cloud, and eLock key cards written at the front desk. Choose which the property uses.'),
            self::Kiosks => __('Self check-in kiosks: pairing, settings and the kiosk API for this property.'),
            self::BookingEngine => __('Let guests book and pay online, on a booking website or through the API.'),
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
            self::DoorLocks => DoorLocks::class,
            self::Kiosks => Kiosks::class,
            self::BookingEngine => BookingEngine::class,
        };
    }
}
