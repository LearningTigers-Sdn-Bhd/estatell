<?php

namespace App\Http\Resources\ApiAccess;

use App\Models\Kiosk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to a successful pairing. The resource is the array returned by `PairKiosk`.
 *
 * @property array{kiosk: Kiosk, plain: string} $resource
 */
class PairedKioskResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->resource['kiosk']->property;

        return [
            'token' => $this->resource['plain'],
            'property' => ['id' => $property->id, 'name' => $property->name],
        ];
    }
}
