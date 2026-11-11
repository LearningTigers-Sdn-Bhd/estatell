<?php

namespace App\Http\Resources\Booking\V1;

use App\Models\Amenity;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property RoomType $resource
 */
class BookingRoomTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $roomType = $this->resource;

        return [
            'id' => $roomType->id,
            'name' => $roomType->name,
            'description' => $roomType->description,
            'photos' => $roomType->getMedia(RoomType::PHOTOS)->map(fn (Media $photo): array => [
                'url' => $photo->getUrl('web'),
                'thumb_url' => $photo->getUrl('thumb'),
            ])->values()->all(),
            'amenities' => $roomType->amenities->map(fn (Amenity $amenity): array => [
                'name' => $amenity->name,
                'category' => $amenity->category->name,
                'icon' => $amenity->icon,
            ])->values()->all(),
            'sell_unit' => $roomType->sell_unit->value,
            'max_adults' => $roomType->max_adults,
            'max_children' => $roomType->max_children,
            'base_occupancy' => $roomType->base_occupancy,
            'smoking_allowed' => $roomType->smoking_allowed,
            'pets_allowed' => $roomType->pets_allowed,
        ];
    }
}
