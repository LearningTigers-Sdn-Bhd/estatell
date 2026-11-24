<?php

namespace App\Enums;

enum PosDiningKind: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case RoomService = 'room_service';
    case Poolside = 'poolside';
    case Minibar = 'minibar';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => __('Dine-in'),
            self::Takeaway => __('Takeaway'),
            self::RoomService => __('Room service'),
            self::Poolside => __('Poolside'),
            self::Minibar => __('Minibar'),
            self::Delivery => __('Delivery'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
