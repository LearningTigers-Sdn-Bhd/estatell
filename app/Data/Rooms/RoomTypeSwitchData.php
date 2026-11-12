<?php

namespace App\Data\Rooms;

use App\Models\Property;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/**
 * One room type in the breadcrumb switch menu of the room type page.
 */
class RoomTypeSwitchData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
        );
    }

    /**
     * The property's active room types in display order, plus the open one when it is archived.
     *
     * @return list<self>
     */
    public static function forProperty(Property $property, RoomType $current): array
    {
        return array_values($property->roomTypes()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $current->id))
            ->ordered()
            ->get()
            ->map(fn (RoomType $roomType): self => self::fromModel($roomType))
            ->all());
    }
}
