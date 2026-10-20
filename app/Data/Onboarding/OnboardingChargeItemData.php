<?php

namespace App\Data\Onboarding;

use App\Enums\ChargingUnit;
use App\Enums\TransactionCategory;
use App\Models\ChargeItem;
use Spatie\LaravelData\Data;

/**
 * One saved charge item as a row of the onboarding Charge items table. A starter item (it owns a system posting code)
 * shows its category and code as fixed text. `taxIds` falls back to `presetTaxIds` for a starter item with no tax.
 */
class OnboardingChargeItemData extends Data
{
    /** @param list<int> $taxIds */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public TransactionCategory $category,
        public bool $isStarter,
        public ?string $defaultAmount,
        public ChargingUnit $chargingUnit,
        public bool $allowOverride,
        public bool $active,
        public array $taxIds,
    ) {}

    /**
     * @param  list<int>  $presetTaxIds
     */
    public static function fromModel(ChargeItem $chargeItem, array $presetTaxIds): self
    {
        $isStarter = $chargeItem->transactionCode->system_key !== null;
        $taxIds = array_values($chargeItem->transactionCode->applicableTaxes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->all());

        return new self(
            id: $chargeItem->id,
            name: $chargeItem->name,
            code: $chargeItem->transactionCode->code,
            category: $chargeItem->transactionCode->category,
            isStarter: $isStarter,
            defaultAmount: $chargeItem->default_amount,
            chargingUnit: $chargeItem->charging_unit,
            allowOverride: $chargeItem->allow_override,
            active: $chargeItem->active,
            taxIds: $isStarter && $taxIds === [] ? $presetTaxIds : $taxIds,
        );
    }
}
