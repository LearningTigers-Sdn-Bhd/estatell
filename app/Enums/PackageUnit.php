<?php

namespace App\Enums;

/**
 * What a package price is charged on: each adult, or the whole room. A Bed room type counts one guest per bed.
 */
enum PackageUnit: string
{
    case PerGuest = 'per_guest';
    case PerRoom = 'per_room';

    public function label(): string
    {
        return match ($this) {
            self::PerGuest => __('Per guest'),
            self::PerRoom => __('Per room'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label()],
            self::cases(),
        );
    }
}
