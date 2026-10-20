<?php

namespace App\Services\Onboarding;

use App\Enums\TaxRateType;
use App\Models\Property;

/**
 * The taxes a new charge item row, and a starter item with no tax, starts with on the Charge items table: the enabled
 * percentage taxes that apply to all guests (SST, service charge). Flat and foreign-only taxes (tourism tax) are left
 * out. Reads only; nothing is saved until the owner clicks Save.
 */
class ChargeItemTaxPreselection
{
    /**
     * @return list<int>
     */
    public function forProperty(Property $property): array
    {
        return array_values($property->taxes()
            ->where('enabled', true)
            ->where('rate_type', TaxRateType::Percent->value)
            ->where('foreign_guests_only', false)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all());
    }
}
