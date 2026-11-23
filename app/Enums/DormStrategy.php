<?php

namespace App\Enums;

use App\Services\Channels\Dorm\BedAsUnitStrategy;
use App\Services\Channels\Dorm\DormChannelStrategy;
use App\Services\Channels\Dorm\NativeDormStrategy;

/**
 * How a dorm (a Bed room type) is sent to the channel manager. See docs/21-channels/03-dorm-strategies.md.
 */
enum DormStrategy: string
{
    case BedAsUnit = 'bed_as_unit';
    case NativeDorm = 'native_dorm';

    public function label(): string
    {
        return match ($this) {
            self::BedAsUnit => __('Bed as unit'),
            self::NativeDorm => __('Native dorm'),
        };
    }

    /**
     * @return class-string<DormChannelStrategy>
     */
    public function strategy(): string
    {
        return match ($this) {
            self::BedAsUnit => BedAsUnitStrategy::class,
            self::NativeDorm => NativeDormStrategy::class,
        };
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return [self::BedAsUnit];
    }

    public function description(): string
    {
        return match ($this) {
            self::BedAsUnit => __('Sell each bed as a separate room on connected channels. Prices and availability are per bed.'),
            self::NativeDorm => __('Use the channel manager\'s native dorm room type. Available only after channel verification is complete.'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string, disabled: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $strategy): array => [
                'value' => $strategy->value,
                'label' => $strategy->label(),
                'description' => $strategy->description(),
                'disabled' => ! in_array($strategy, self::available(), true),
            ],
            self::cases(),
        );
    }
}
