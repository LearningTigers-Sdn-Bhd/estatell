<?php

namespace App\Enums;

/**
 * Where a reservation came from.
 */
enum ReservationSource: string
{
    case Direct = 'direct';
    case WalkIn = 'walk_in';
    case Phone = 'phone';
    case Channel = 'channel';
    case Portal = 'portal';

    public function label(): string
    {
        return match ($this) {
            self::Direct => __('Direct'),
            self::WalkIn => __('Walk-in'),
            self::Phone => __('Phone'),
            self::Channel => __('Channel'),
            self::Portal => __('Portal'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $source): array => ['value' => $source->value, 'label' => $source->label()],
            self::cases(),
        );
    }
}
