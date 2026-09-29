<?php

namespace App\Enums;

enum DiscountScope: string
{
    case RoomOnly = 'room_only';
    case AllCharges = 'all_charges';
    case Selected = 'selected';

    public function label(): string
    {
        return match ($this) {
            self::RoomOnly => __('Room only'),
            self::AllCharges => __('All charges'),
            self::Selected => __('Selected charges'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $scope): array => ['value' => $scope->value, 'label' => $scope->label()],
            self::cases(),
        );
    }
}
