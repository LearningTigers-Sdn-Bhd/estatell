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
     * @param  list<self>|null  $available  The pickable units; the rest show disabled. Null makes every unit pickable.
     * @return list<array{value: string, label: string, disabled: bool, description: string|null}>
     */
    public static function options(?array $available = null): array
    {
        return array_map(
            function (self $unit) use ($available): array {
                $pickable = $available === null || in_array($unit, $available, true);

                return [
                    'value' => $unit->value,
                    'label' => $unit->label(),
                    'disabled' => ! $pickable,
                    'description' => $pickable ? null : __('Not available yet'),
                ];
            },
            self::cases(),
        );
    }
}
