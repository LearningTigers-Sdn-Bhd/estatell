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

    /**
     * The unit statuses that take a boat trip in this direction. Boat in only before check-in; Boat out until check-out.
     *
     * @return list<UnitStatus>
     */
    public function unitStatuses(): array
    {
        return match ($this) {
            self::In => [UnitStatus::Confirmed],
            self::Out => [UnitStatus::Confirmed, UnitStatus::CheckedIn],
        };
    }

    /** The stay date a trip in this direction sits on: the arrival for Boat in, the departure for Boat out. */
    public function tripDateColumn(): string
    {
        return match ($this) {
            self::In => 'arrival_date',
            self::Out => 'departure_date',
        };
    }
}
