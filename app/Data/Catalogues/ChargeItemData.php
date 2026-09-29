<?php

namespace App\Data\Catalogues;

use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/**
 * One row of the charge-item list, and the values of its edit form.
 */
class ChargeItemData extends Data
{
    /** @param list<int> $taxIds */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public TransactionCategory $category,
        public bool $isStarter,
        public ?string $description,
        public ?string $defaultAmount,
        public ChargingUnit $chargingUnit,
        public bool $allowOverride,
        public bool $active,
        public array $taxIds,
    ) {}

    public static function fromModel(ChargeItem $chargeItem): self
    {
        return new self(
            id: $chargeItem->id,
            name: $chargeItem->name,
            code: $chargeItem->transactionCode->code,
            category: $chargeItem->transactionCode->category,
            isStarter: $chargeItem->transactionCode->system_key !== null,
            description: $chargeItem->description,
            defaultAmount: $chargeItem->default_amount,
            chargingUnit: $chargeItem->charging_unit,
            allowOverride: $chargeItem->allow_override,
            active: $chargeItem->active,
            taxIds: array_values($chargeItem->transactionCode->applicableTaxes
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->all()),
        );
    }
}
