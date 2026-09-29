<?php

namespace App\Actions\Catalogues;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\SystemTransactionCode;
use App\Models\Discount;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedDiscounts
{
    /**
     * Create the starter discount that owns the DISCOUNT system code.
     * An existing starter discount keeps every property-owned value when this runs again.
     */
    public function __invoke(Property $property, string $countryCode): void
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Discount presets are not available for country [{$countryCode}].");
        }

        DB::transaction(function () use ($property): void {
            $transactionCode = $property->transactionCodes()
                ->where('system_key', SystemTransactionCode::Discount->value)
                ->firstOrFail();

            if ($transactionCode->discount()->exists()) {
                return;
            }

            $nameTaken = $property->discounts()
                ->whereRaw('lower(name) = ?', [mb_strtolower($transactionCode->name)])
                ->exists();

            if ($nameTaken) {
                return;
            }

            $discount = new Discount([
                'name' => $transactionCode->name,
                'type' => DiscountType::Open,
                'value' => null,
                'allow_override' => true,
                'scope' => DiscountScope::AllCharges,
                'position' => ((int) $property->discounts()->active()->max('position')) + 1,
                'active' => $transactionCode->active,
            ]);
            $discount->property_id = $property->id;
            $discount->transaction_code_id = $transactionCode->id;
            $discount->save();
        });
    }
}
