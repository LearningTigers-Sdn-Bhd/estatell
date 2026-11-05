<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Services\Kiosk\KioskPinStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ pin: { status, code?, valid_from?, valid_until? } }`. The code is only present when `status` is `ready`.
 *
 * @property KioskPinStatus $resource
 */
class KioskPinResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['pin' => array_filter([
            'status' => $this->resource->status,
            'code' => $this->resource->code,
            'valid_from' => $this->resource->validFrom?->toRfc3339String(),
            'valid_until' => $this->resource->validUntil?->toRfc3339String(),
        ], fn (mixed $value): bool => $value !== null)];
    }
}
