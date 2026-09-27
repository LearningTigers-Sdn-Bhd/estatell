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
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $strategy): array => ['value' => $strategy->value, 'label' => $strategy->label()],
            self::cases(),
        );
    }
}
