<?php

namespace App\Enums;

use App\Features\AccountingIntegration;
use App\Features\BedRoomTypes;
use App\Features\Boats;
use App\Features\BookingEngine;
use App\Features\ChannelManager;
use App\Features\Concierge;
use App\Features\DoorLocks;
use App\Features\EInvoicing;
use App\Features\Facilities;
use App\Features\Kiosks;
use App\Features\NativePos;
use App\Features\PosIntegration;

/**
 * Per-property features that platform admins turn on or off. Each case maps to a Pennant feature class.
 */
enum PlatformFeature: string
{
    case ChannelManager = 'channel-manager';
    case Boats = 'boats';
    case AccountingIntegration = 'accounting-integration';
    case DoorLocks = 'door-locks';
    case EInvoicing = 'e-invoicing';
    case Kiosks = 'kiosks';
    case BookingEngine = 'booking-engine';
    case Concierge = 'concierge';
    case BedRoomTypes = 'bed-room-types';
    case PosIntegration = 'pos-integration';
    case Facilities = 'facilities';
    case NativePos = 'native-pos';

    public function label(): string
    {
        return match ($this) {
            self::ChannelManager => __('Channel manager'),
            self::Boats => __('Boats'),
            self::AccountingIntegration => __('Accounting integration'),
            self::DoorLocks => __('Door locks'),
            self::EInvoicing => __('E-invoicing'),
            self::Kiosks => __('Kiosks'),
            self::BookingEngine => __('Booking engine'),
            self::Concierge => __('Concierge page'),
            self::BedRoomTypes => __('Dormitory features'),
            self::PosIntegration => __('POS integration'),
            self::Facilities => __('Facilities and events'),
            self::NativePos => __('Native POS'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ChannelManager => __('Connect this property to a channel manager and sync OTAs.'),
            self::Boats => __('Boat departures, fares and boat trips for island and jetty properties.'),
            self::AccountingIntegration => __('Push each closed business date to the property\'s accounting software.'),
            self::DoorLocks => __('Door locks for each stay: TTLock PINs and cards from the cloud, and eLock key cards written at the front desk. Choose which the property uses.'),
            self::EInvoicing => __('Submit folios, city ledger invoices and consolidated month-end e-invoices to LHDN MyInvois.'),
            self::Kiosks => __('Self check-in kiosks: pairing, settings and the kiosk API for this property.'),
            self::BookingEngine => __('Let guests book and pay online, on a booking website or through the API.'),
            self::Concierge => __('Guest self check-in on the guest\'s own phone, from a lobby QR code or the pre-check-in link.'),
            self::BedRoomTypes => __('Create room types sold by bed, for dorms. Room types that already sell beds keep working while this is off.'),
            self::PosIntegration => __('Outlets, restaurant meal check-in and room charges from a POS (Loyverse or the POS API).'),
            self::Facilities => __('Halls, equipment and activities staff book by date and time, and event folios for guests who do not stay.'),
            self::NativePos => __('Estatell\'s built-in point of sale: registers, shifts, menu, orders and room charges, with no external POS.'),
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
            self::EInvoicing => EInvoicing::class,
            self::Kiosks => Kiosks::class,
            self::BookingEngine => BookingEngine::class,
            self::Concierge => Concierge::class,
            self::BedRoomTypes => BedRoomTypes::class,
            self::PosIntegration => PosIntegration::class,
            self::Facilities => Facilities::class,
            self::NativePos => NativePos::class,
        };
    }
}
