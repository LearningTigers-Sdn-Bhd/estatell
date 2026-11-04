<?php

namespace App\Http\Resources\Booking\V1;

use App\Enums\BookingHoldStatus;
use App\Models\BookingHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hold as a client sees it. It holds no guest details, because the answer to a write is stored for replay. A Held hold
 * past its expiry reads as Expired, even before the job has run.
 *
 * @property BookingHold $resource
 */
class BookingHoldResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hold = $this->resource;
        $isPastExpiry = $hold->status === BookingHoldStatus::Held && ! $hold->expires_at->isFuture();

        return [
            'token' => $hold->token,
            'status' => ($isPastExpiry ? BookingHoldStatus::Expired : $hold->status)->value,
            'expires_at' => $hold->expires_at->toIso8601String(),
            'online_total' => $hold->total_amount,
        ];
    }
}
