<?php

namespace App\Http\Resources\Kiosk\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ eligible, reason? }`, given the reason code or null.
 *
 * @property string|null $resource
 */
class KioskEligibilityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource === null ? ['eligible' => true] : ['eligible' => false, 'reason' => $this->resource];
    }
}
