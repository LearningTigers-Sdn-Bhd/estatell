<?php

namespace App\Data\Rates;

use App\Enums\ChargingUnit;
use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/** A charge item an inclusion can post to, with the values the form prefills when it is picked. */
class RatePlanInclusionChargeItemData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $active,
        public ChargingUnit $chargingUnit,
        public ?string $defaultAmount,
    ) {}

    public static function fromModel(ChargeItem $chargeItem): self
    {
        return new self(
            id: $chargeItem->id,
            name: $chargeItem->name,
            active: $chargeItem->active,
            chargingUnit: $chargeItem->charging_unit,
            defaultAmount: $chargeItem->default_amount,
        );
    }
}
