<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Actions\Kiosk\KioskCardResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ registered, valid_until }`.
 *
 * @property KioskCardResult $resource
 */
class KioskCardRegisteredResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['registered' => true, 'valid_until' => $this->resource->validUntil?->toRfc3339String()];
    }
}
