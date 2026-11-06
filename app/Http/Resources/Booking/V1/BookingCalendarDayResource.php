<?php

namespace App\Http\Resources\Booking\V1;

use App\Services\BookingEngine\BookingCalendarDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property BookingCalendarDay $resource
 */
class BookingCalendarDayResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->resource->date,
            'lowest_price' => $this->resource->lowestPrice,
            'closed' => $this->resource->closed,
        ];
    }
}
