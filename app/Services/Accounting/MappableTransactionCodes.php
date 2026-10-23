<?php

namespace App\Services\Accounting;

use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Database\Eloquent\Builder;

/**
 * The active transaction codes a property can map to accounts. The Opening balance code is left out because the daily
 * journal never reads it. It reads only.
 */
class MappableTransactionCodes
{
    /**
     * @return Builder<TransactionCode>
     */
    public function query(Property $property): Builder
    {
        return TransactionCode::query()
            ->where('property_id', $property->id)
            ->where('active', true)
            ->where(fn (Builder $codes) => $codes
                ->whereNull('system_key')
                ->orWhere('system_key', '!=', SystemTransactionCode::OpeningBalance->value))
            ->whereIn('kind', [TransactionKind::Charge->value, TransactionKind::Tax->value, TransactionKind::Payment->value, TransactionKind::Adjustment->value]);
    }
}
