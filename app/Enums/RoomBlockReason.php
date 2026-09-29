<?php

namespace App\Enums;

/**
 * Why a room or bed is taken out of sale. See docs/05-rooms/04-room-blocks-and-statuses.md.
 */
enum RoomBlockReason: string
{
    case Maintenance = 'maintenance';
    case OwnerUse = 'owner_use';
    case OutOfOrder = 'out_of_order';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => __('Maintenance'),
            self::OwnerUse => __('Owner use'),
            self::OutOfOrder => __('Out of order'),
            self::Other => __('Other'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
