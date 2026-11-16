<?php

namespace App\Enums;

use App\Features\BedRoomTypes;
use App\Models\Property;
use Laravel\Pennant\Feature;

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
     * The sell units a new room type may pick at the property. Bed needs the BedRoomTypes feature; a room type that
     * already sells beds keeps it while the feature is off.
     *
     * @return list<self>
     */
    public static function availableFor(Property $property): array
    {
        return Feature::for($property)->active(BedRoomTypes::class) ? self::cases() : [self::Room];
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
