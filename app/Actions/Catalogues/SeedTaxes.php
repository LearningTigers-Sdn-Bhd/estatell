<?php

namespace App\Actions\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\Property;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedTaxes
{
    /** Create disabled country presets while preserving property-owned changes. */
    public function __invoke(Property $property, string $countryCode): void
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Tax presets are not available for country [{$countryCode}].");
        }

        DB::transaction(function () use ($property): void {
            $roomRevenue = $property->transactionCodes()->where('system_key', SystemTransactionCode::RoomRevenue->value)->firstOrFail();
            $presets = [
                ['system_key' => SystemTransactionCode::SstTax->value, 'name' => 'Service Tax (SST)', 'code' => 'SST', 'rate_type' => TaxRateType::Percent, 'rate' => '8.00', 'foreign_guests_only' => false],
                ['system_key' => SystemTransactionCode::TourismTax->value, 'name' => 'Tourism tax', 'code' => 'TTX', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00', 'foreign_guests_only' => true],
            ];

            foreach ($presets as $preset) {
                $postingCode = $property->transactionCodes()->where('system_key', $preset['system_key'])->firstOrFail();
                $tax = $property->taxes()->whereBelongsTo($postingCode, 'transactionCode')->first();

                if ($tax instanceof Tax) {
                    continue;
                }

                $tax = $property->taxes()->create([
                    'transaction_code_id' => $postingCode->id,
                    'name' => $preset['name'],
                    'code' => $preset['code'],
                    'rate_type' => $preset['rate_type'],
                    'rate' => $preset['rate'],
                    'is_inclusive' => false,
                    'foreign_guests_only' => $preset['foreign_guests_only'],
                    'registration_number' => null,
                    'enabled' => false,
                ]);
                $tax->applicableTransactionCodes()->attach($roomRevenue->id);
            }
        });
    }
}
