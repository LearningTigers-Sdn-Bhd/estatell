<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Actions\Kiosk\KioskIdentityResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property KioskIdentityResult $resource
 */
class KioskIdentityResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'accepted' => $this->resource->accepted,
            'nationality_required' => $this->resource->nationalityRequired,
        ];
    }
}
