<?php

namespace App\Http\Resources\Kiosk\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ signed }`: the whole answer of `POST /stays/{id}/registration-card/sign`.
 *
 * @property bool $resource
 */
class KioskSignedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['signed' => $this->resource];
    }
}
