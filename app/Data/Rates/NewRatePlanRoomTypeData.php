<?php

namespace App\Data\Rates;

use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Models\RoomType;
use Spatie\LaravelData\Data;

/** One room type checkbox of the new rate plan form, with the pricing bases that can sell it. */
class NewRatePlanRoomTypeData extends Data
{
    /**
     * @param  list<PricingBasis>  $fitsBases
     */
    public function __construct(
        public int $id,
        public string $name,
        public SellUnit $sellUnit,
        public string $sellUnitLabel,
        public array $fitsBases,
    ) {}

    public static function fromModel(RoomType $roomType): self
    {
        return new self(
            id: $roomType->id,
            name: $roomType->name,
            sellUnit: $roomType->sell_unit,
            sellUnitLabel: $roomType->sell_unit->label(),
            fitsBases: array_values(array_filter(
                PricingBasis::cases(),
                fn (PricingBasis $basis): bool => $basis->fits($roomType->sell_unit),
            )),
        );
    }
}
