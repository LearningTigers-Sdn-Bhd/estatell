<?php

namespace App\Actions\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Property\CountryProfile;
use Illuminate\Support\Facades\DB;

class SeedTaxes
{
    /** Create disabled country presets while preserving property-owned changes. */
    public function __invoke(Property $property, CountryProfile $profile): void
    {
        DB::transaction(function () use ($property, $profile): void {
            $roomRevenueCodeIds = $property->transactionCodes()
                ->whereIn('system_key', SystemTransactionCode::roomRevenueKeys())
                ->pluck('id')
                ->all();

            foreach ($profile->taxPresets() as $preset) {
                $postingCode = $property->transactionCodes()->where('system_key', $preset['systemCode']->value)->firstOrFail();
                $tax = $property->taxes()->whereBelongsTo($postingCode, 'transactionCode')->first();

                if ($tax instanceof Tax) {
                    continue;
                }

                $tax = $property->taxes()->create([
                    'transaction_code_id' => $postingCode->id,
                    'name' => $preset['name'],
                    'code' => $preset['code'],
                    'rate_type' => $preset['rateType'],
                    'rate' => $preset['rate'],
                    'is_inclusive' => false,
                    'foreign_guests_only' => $preset['foreignGuestsOnly'],
                    'collect_at_check_in' => true,
                    'registration_number' => null,
                    'enabled' => false,
                ]);
                $tax->applicableTransactionCodes()->attach($roomRevenueCodeIds);
            }
        });
    }
}
