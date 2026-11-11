<?php

namespace App\Enums;

use App\Features\BookingEngine;
use App\Features\ChannelManager;
use App\Services\Commissions\BookingEngineCommission;
use App\Services\Commissions\ChannelManagerCommission;
use App\Services\Commissions\CommissionRule;

/**
 * A service Estatell charges commission on. Each case is turned on per property by its feature flag.
 * A new service is one case here and one commission rule. See docs/30-commission-fees/02-commission-rules.md.
 */
enum CommissionService: string
{
    case BookingEngine = 'booking_engine';
    case ChannelManager = 'channel_manager';

    public function label(): string
    {
        return match ($this) {
            self::BookingEngine => __('Booking engine'),
            self::ChannelManager => __('Channel manager'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $service): array => ['value' => $service->value, 'label' => $service->label()], self::cases());
    }

    /**
     * The rule that decides this service's commission.
     *
     * @return class-string<CommissionRule>
     */
    public function rule(): string
    {
        return match ($this) {
            self::BookingEngine => BookingEngineCommission::class,
            self::ChannelManager => ChannelManagerCommission::class,
        };
    }

    /**
     * @return class-string
     */
    public function featureClass(): string
    {
        return match ($this) {
            self::BookingEngine => BookingEngine::class,
            self::ChannelManager => ChannelManager::class,
        };
    }
}
