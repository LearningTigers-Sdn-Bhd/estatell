<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Services\Kiosk\KioskBookingLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property KioskBookingLookup $resource A lookup that found a stay.
 */
class KioskBookingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Needs the stay's `reservation` and `roomType` relations loaded, as `KioskBookingFinder` leaves them.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stay = $this->resource->stay;

        return [
            'stay_id' => $stay->id,
            'booking_ref' => $stay->reservation->reference,
            'guest_name' => $this->resource->guestName,
            'arrival' => $stay->arrival_date->toDateString(),
            'departure' => $stay->departure_date->toDateString(),
            'unit_type' => $stay->roomType->name,
            'steps_required' => $this->resource->steps,
            ...($this->resource->state ?? []),
        ];
    }
}
