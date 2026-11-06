<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Enums\KioskKeyMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `{ room: { number }, key_mode }`. Estatell keeps no walking directions for a room, so `directions` is left out.
 *
 * @property array{room: string|null, key_mode: KioskKeyMode} $resource
 */
class KioskCheckInResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['room' => ['number' => $this->resource['room']], 'key_mode' => $this->resource['key_mode']->value];
    }
}
