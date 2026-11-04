<?php

namespace App\Http\Resources\ApiAccess;

use App\Models\ApiToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to a successful pairing. The resource is the array returned by `PairKiosk`.
 *
 * @property array{token: ApiToken, plain: string} $resource
 */
class PairedKioskResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->resource['token']->property;

        return [
            'token' => $this->resource['plain'],
            'property' => ['id' => $property->id, 'name' => $property->name],
        ];
    }
}
