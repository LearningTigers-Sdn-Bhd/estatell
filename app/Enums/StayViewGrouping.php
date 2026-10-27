<?php

namespace App\Enums;

/**
 * How the Timeline View and Room View head their rows and cards. It changes the layout only, not which rooms appear.
 * See docs/18-front-desk/03-stay-view.md.
 */
enum StayViewGrouping: string
{
    case None = 'none';
    case RoomGroup = 'room_group';
    case RoomType = 'room_type';

    public function label(): string
    {
        return match ($this) {
            self::None => __('None'),
            self::RoomGroup => __('Room group'),
            self::RoomType => __('Room type'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $grouping): array => ['value' => $grouping->value, 'label' => $grouping->label()],
            self::cases(),
        );
    }
}
