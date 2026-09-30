<?php

namespace App\Data\Rates;

use App\Models\PackagePrice;
use Spatie\LaravelData\Data;

/** One row of a package plan's price grid: the stay price for a room type and arrival period. */
class PackagePriceData extends Data
{
    public function __construct(
        public int $id,
        public int $roomTypeId,
        public string $arrivalFrom,
        public string $arrivalTo,
        public string $amount,
    ) {}

    public static function fromModel(PackagePrice $price): self
    {
        return new self(
            id: $price->id,
            roomTypeId: $price->room_type_id,
            arrivalFrom: $price->arrival_from->toDateString(),
            arrivalTo: $price->arrival_to->toDateString(),
            amount: $price->amount,
        );
    }
}
