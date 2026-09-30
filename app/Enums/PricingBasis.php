<?php

namespace App\Enums;

use App\Services\Rates\Pricing\PackagePricing;
use App\Services\Rates\Pricing\PerBedPricing;
use App\Services\Rates\Pricing\PerGuestPricing;
use App\Services\Rates\Pricing\PerRoomPricing;
use App\Services\Rates\Pricing\StayPricing;

/**
 * How a rate plan prices a stay. It decides which room types the plan can sell.
 */
enum PricingBasis: string
{
    case PerRoom = 'per_room';
    case PerGuest = 'per_guest';
    case PerBed = 'per_bed';
    case Package = 'package';

    public function label(): string
    {
        return match ($this) {
            self::PerRoom => __('Per room'),
            self::PerGuest => __('Per guest'),
            self::PerBed => __('Per bed'),
            self::Package => __('Package'),
        };
    }

    /**
     * What plans with this basis sell: rooms, beds, or either.
     */
    public function family(): string
    {
        return match ($this) {
            self::PerRoom, self::PerGuest => __('Room'),
            self::PerBed => __('Bed'),
            self::Package => __('Room or bed'),
        };
    }

    /**
     * Whether a plan with this basis can sell a room type with the given sell unit.
     * Package is the only basis that fits both.
     */
    public function fits(SellUnit $sellUnit): bool
    {
        return match ($this) {
            self::PerRoom, self::PerGuest => $sellUnit === SellUnit::Room,
            self::PerBed => $sellUnit === SellUnit::Bed,
            self::Package => true,
        };
    }

    /**
     * Whether a plan with this basis prices children by age group. A package does when it is charged per guest.
     */
    public function usesChildPrices(?PackageUnit $packageUnit): bool
    {
        return match ($this) {
            self::PerGuest => true,
            self::Package => $packageUnit === PackageUnit::PerGuest,
            self::PerRoom, self::PerBed => false,
        };
    }

    /**
     * The class that prices a stay for this basis.
     *
     * @return class-string<StayPricing>
     */
    public function strategy(): string
    {
        return match ($this) {
            self::PerRoom => PerRoomPricing::class,
            self::PerGuest => PerGuestPricing::class,
            self::PerBed => PerBedPricing::class,
            self::Package => PackagePricing::class,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $basis): array => ['value' => $basis->value, 'label' => $basis->label()],
            self::cases(),
        );
    }
}
