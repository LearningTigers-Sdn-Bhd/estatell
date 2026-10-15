<?php

namespace App\Enums;

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
