<?php

namespace App\Actions\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\Property;
use App\Services\Catalogues\RoomRevenueTaxIds;
use Illuminate\Support\Facades\DB;

class SeedDayUseTaxes
{
    public function __construct(private RoomRevenueTaxIds $roomRevenueTaxIds) {}

    /**
     * Give DAY_USE the taxes of the Taxes tab, except flat taxes: a fixed amount per room-night does not fit an hourly stay.
     * Run when the property's first Day use plan is created. Does nothing when the property has no DAY_USE code.
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            $dayUse = $property->transactionCodes()->where('system_key', SystemTransactionCode::DayUseRevenue->value)->lockForUpdate()->first();

            if ($dayUse === null) {
                return;
            }

            $taxIds = $property->taxes()
                ->whereKey($this->roomRevenueTaxIds->forRoom($property))
                ->where('rate_type', '<>', TaxRateType::Flat)
                ->pluck('id')
                ->all();

            $dayUse->applicableTaxes()->sync($taxIds);
        });
    }
}
