<?php

namespace App\Enums;

/**
 * What a room type sells: the whole room, or one bed in it.
 */
enum SellUnit: string
{
    case Room = 'room';
    case Bed = 'bed';

    public function label(): string
    {
        return match ($this) {
            self::Room => __('Room'),
            self::Bed => __('Bed'),
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
