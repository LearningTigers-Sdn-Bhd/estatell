<?php

namespace App\Enums;

/**
 * Where an amenity applies: the whole property or one room type.
 */
enum AmenityType: string
{
    case Property = 'property';
    case Room = 'room';

    public function label(): string
    {
        return match ($this) {
            self::Property => __('Property'),
            self::Room => __('Room'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
