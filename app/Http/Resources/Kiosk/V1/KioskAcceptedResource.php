<?php

namespace App\Http\Resources\Kiosk\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ accepted }`: the whole answer of `POST /stays/{id}/guest`.
 *
 * @property bool $resource
 */
class KioskAcceptedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['accepted' => $this->resource];
    }
}
