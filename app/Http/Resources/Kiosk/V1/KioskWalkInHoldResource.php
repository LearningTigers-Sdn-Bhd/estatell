<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Actions\Kiosk\KioskWalkInResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property KioskWalkInResult $resource */
class KioskWalkInHoldResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'stay_id' => $this->resource->hold->reservation_unit_id,
            'booking_ref' => $this->resource->hold->stay->reservation->reference,
            'hold_expires_at' => $this->resource->hold->expires_at->toRfc3339String(),
            'deposit_due' => ['amount_minor' => $this->resource->deposit->getMinorAmount()->toInt(), 'currency' => $this->resource->deposit->getCurrency()->getCurrencyCode()],
        ];
    }
}
