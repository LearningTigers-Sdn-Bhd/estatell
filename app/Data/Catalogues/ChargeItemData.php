<?php

namespace App\Data\Catalogues;

use App\Enums\ChargingUnit;
use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/**
 * One row of the charge-item list, and the values of its edit form.
 */
class ChargeItemData extends Data
{
    public function __construct(
        public int $id,
        public int $transactionCodeId,
        public string $transactionCode,
        public string $name,
        public ?string $description,
        public string $defaultAmount,
        public ChargingUnit $chargingUnit,
        public bool $allowOverride,
        public bool $active,
    ) {}

    public static function fromModel(ChargeItem $chargeItem): self
    {
        return new self(
            id: $chargeItem->id,
            transactionCodeId: $chargeItem->transaction_code_id,
            transactionCode: __(':code — :name', [
                'code' => $chargeItem->transactionCode->code,
                'name' => $chargeItem->transactionCode->name,
            ]),
            name: $chargeItem->name,
            description: $chargeItem->description,
            defaultAmount: $chargeItem->default_amount,
            chargingUnit: $chargeItem->charging_unit,
            allowOverride: $chargeItem->allow_override,
            active: $chargeItem->active,
        );
    }
}
