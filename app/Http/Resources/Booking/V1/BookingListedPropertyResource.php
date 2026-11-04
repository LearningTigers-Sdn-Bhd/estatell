<?php

namespace App\Http\Resources\Booking\V1;

use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Property $resource
 */
class BookingListedPropertyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'slug' => $this->resource->slug,
            'name' => $this->resource->name,
            'city' => $this->resource->city,
        ];
    }
}
