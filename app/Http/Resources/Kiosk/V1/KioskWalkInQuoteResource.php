<?php

namespace App\Http\Resources\Kiosk\V1;

use App\Services\Kiosk\KioskWalkInOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property list<KioskWalkInOffer> $resource */
class KioskWalkInQuoteResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['options' => array_map(fn (KioskWalkInOffer $offer): array => [
            'room_type_id' => (string) $offer->roomType->id, 'name' => $offer->roomType->name,
            'total' => ['amount_minor' => $offer->total->getMinorAmount()->toInt(), 'currency' => $offer->total->getCurrency()->getCurrencyCode()],
            'available' => $offer->available,
        ], $this->resource)];
    }
}
