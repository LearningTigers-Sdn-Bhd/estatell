<?php

namespace App\Data\Boats;

use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/** A Transport charge item offered as a boat fare. */
class BoatFareData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $amount,
        public bool $active,
    ) {}

    public static function fromModel(ChargeItem $chargeItem): self
    {
        return new self(
            id: $chargeItem->id,
            name: $chargeItem->name,
            amount: $chargeItem->default_amount,
            active: $chargeItem->active,
        );
    }
}
