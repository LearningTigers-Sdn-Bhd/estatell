<?php

namespace App\Data\Catalogues;

use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/** A charge item available for payment-method surcharge configuration. */
class SurchargeChargeItemData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public bool $active,
    ) {}

    public static function fromModel(ChargeItem $chargeItem): self
    {
        return new self(
            id: $chargeItem->id,
            label: __(':name — :code', [
                'name' => $chargeItem->name,
                'code' => $chargeItem->transactionCode->code,
            ]),
            active: $chargeItem->active,
        );
    }
}
