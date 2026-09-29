<?php

namespace App\Actions\Catalogues;

use App\Enums\ChargingUnit;
use App\Enums\SystemTransactionCode;
use App\Models\ChargeItem;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedChargeItems
{
    /**
     * Create the starter charge items that own the general system charge codes.
     * Existing items keep every property-owned value when this runs again.
     */
    public function __invoke(Property $property, string $countryCode): void
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Charge-item presets are not available for country [{$countryCode}].");
        }

        DB::transaction(function () use ($property): void {
            foreach ($this->systemCodes() as $systemCode) {
                $transactionCode = $property->transactionCodes()
                    ->where('system_key', $systemCode->value)
                    ->firstOrFail();

                if ($transactionCode->chargeItem()->exists()) {
                    continue;
                }

                $nameTaken = $property->chargeItems()
                    ->whereRaw('lower(name) = ?', [mb_strtolower($transactionCode->name)])
                    ->exists();

                if ($nameTaken) {
                    continue;
                }

                $chargeItem = new ChargeItem([
                    'name' => $transactionCode->name,
                    'description' => null,
                    'default_amount' => null,
                    'charging_unit' => ChargingUnit::PerItem,
                    'allow_override' => true,
                    'position' => ((int) $property->chargeItems()->active()->max('position')) + 1,
                    'active' => $transactionCode->active,
                ]);
                $chargeItem->property_id = $property->id;
                $chargeItem->transaction_code_id = $transactionCode->id;
                $chargeItem->save();
            }
        });
    }

    /** @return list<SystemTransactionCode> */
    private function systemCodes(): array
    {
        return [
            SystemTransactionCode::FoodAndBeverageRevenue,
            SystemTransactionCode::LaundryRevenue,
            SystemTransactionCode::MinibarRevenue,
            SystemTransactionCode::FeeRevenue,
        ];
    }
}
