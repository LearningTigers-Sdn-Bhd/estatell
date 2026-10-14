<?php

namespace App\Actions\Catalogues;

use App\Enums\SurchargeType;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Services\Property\CountryProfile;
use Illuminate\Support\Facades\DB;

class SeedPaymentMethods
{
    /** Create canonical country presets while preserving property-owned changes. */
    public function __invoke(Property $property, CountryProfile $profile): void
    {
        DB::transaction(function () use ($property, $profile): void {
            foreach ($profile->paymentMethodPresets() as $position => $preset) {
                $transactionCode = $property->transactionCodes()
                    ->where('system_key', $preset['systemCode']->value)
                    ->firstOrFail();

                if ($property->paymentMethods()->whereBelongsTo($transactionCode, 'transactionCode')->exists()) {
                    continue;
                }

                $paymentMethod = new PaymentMethod([
                    'transaction_code_id' => $transactionCode->id,
                    'name' => $transactionCode->name,
                    'type' => $preset['type'],
                    'surcharge_type' => SurchargeType::None,
                    'surcharge_value' => null,
                    'surcharge_charge_item_id' => null,
                    'requires_reference' => $preset['requiresReference'],
                    'position' => $position + 1,
                    'active' => $preset['active'],
                ]);
                $paymentMethod->property_id = $property->id;
                $paymentMethod->save();
            }
        });
    }
}
