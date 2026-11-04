<?php

namespace App\Http\Resources\ApiAccess;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ApiToken $resource
 */
class KioskMeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Needs the `property` relation loaded.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kiosk_name' => $this->resource->name,
            'property_name' => $this->resource->property->name,
        ];
    }
}
