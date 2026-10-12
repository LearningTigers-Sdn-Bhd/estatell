<?php

namespace App\Enums;

use App\Models\BoatDeparture;

enum BoatDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => __('Boat in'),
            self::Out => __('Boat out'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $direction): array => ['value' => $direction->value, 'label' => $direction->label()],
            self::cases(),
        );
    }

    /**
     * The time at the property: the arrival for Boat in, the departure for Boat out. Returns `HH:MM:SS`.
     */
    public function propertyTime(BoatDeparture $departure): string
    {
        return match ($this) {
            self::In => $departure->arrives_at,
            self::Out => $departure->leaves_at,
        };
    }
}
