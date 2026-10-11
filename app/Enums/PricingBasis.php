<?php

namespace App\Enums;

use App\Services\Rates\Pricing\PackagePricing;
use App\Services\Rates\Pricing\PerBedPricing;
use App\Services\Rates\Pricing\PerGuestPricing;
use App\Services\Rates\Pricing\PerRoomPricing;
use App\Services\Rates\Pricing\StayPricing;
use LogicException;

/**
 * How a rate plan prices a stay. It decides which room types the plan can sell.
 */
enum PricingBasis: string
{
    case PerRoom = 'per_room';
    case PerGuest = 'per_guest';
    case PerBed = 'per_bed';
    case Package = 'package';
    case DayUse = 'day_use';

    public function label(): string
    {
        return match ($this) {
            self::PerRoom => __('Per room'),
            self::PerGuest => __('Per guest'),
            self::PerBed => __('Per bed'),
            self::Package => __('Package'),
            self::DayUse => __('Day use'),
        };
    }

    /**
     * What plans with this basis sell: rooms, beds, or either.
     */
    public function family(): string
    {
        return match ($this) {
            self::PerRoom, self::PerGuest, self::DayUse => __('Room'),
            self::PerBed => __('Bed'),
            self::Package => __('Room or bed'),
        };
    }

    /**
     * The tone of the basis badge in the rate plans list.
     */
    public function tone(): Tone
    {
        return match ($this) {
            self::PerRoom => Tone::Info,
            self::PerGuest => Tone::Success,
            self::PerBed => Tone::Warning,
            self::Package => Tone::Neutral,
            self::DayUse => Tone::Info,
        };
    }

    /**
     * The tone of the "sells" badge: rooms, beds, or either.
     */
    public function familyTone(): Tone
    {
        return match ($this) {
            self::PerRoom, self::PerGuest, self::DayUse => Tone::Info,
            self::PerBed => Tone::Warning,
            self::Package => Tone::Neutral,
        };
    }

    /**
     * Whether a plan with this basis can sell a room type with the given sell unit.
     * Package is the only basis that fits both.
     */
    public function fits(SellUnit $sellUnit): bool
    {
        return match ($this) {
            self::PerRoom, self::PerGuest, self::DayUse => $sellUnit === SellUnit::Room,
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
            self::PerRoom, self::PerBed, self::DayUse => false,
        };
    }

    /**
     * The class that prices a stay for this basis. A Day use plan has none: `DayUsePricing` prices a block, not nights.
     *
     * @return class-string<StayPricing>
     *
     * @throws LogicException for DayUse
     */
    public function strategy(): string
    {
        return match ($this) {
            self::PerRoom => PerRoomPricing::class,
            self::PerGuest => PerGuestPricing::class,
            self::PerBed => PerBedPricing::class,
            self::Package => PackagePricing::class,
            self::DayUse => throw new LogicException('A Day use plan is priced by DayUsePricing, not by a nightly strategy.'),
        };
    }

    /**
     * Why a plan with this basis is never sent to a channel, or null when it can be.
     */
    public function channelRefusal(): ?string
    {
        return $this === self::DayUse ? __('Day use plans are sold at the front desk only.') : null;
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
