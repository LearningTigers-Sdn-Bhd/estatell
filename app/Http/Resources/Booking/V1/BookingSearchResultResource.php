<?php

namespace App\Http\Resources\Booking\V1;

use App\Models\Property;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{property: Property, lowestPrice: Money} $resource
 */
class BookingSearchResultResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->resource['property'];
        $lowest = $this->resource['lowestPrice'];

        return [
            'code' => $property->code,
            'slug' => $property->slug,
            'name' => $property->name,
            'city' => $property->city,
            'currency' => $lowest->getCurrency()->getCurrencyCode(),
            'lowest_price' => (string) $lowest->getAmount(),
        ];
    }
}
