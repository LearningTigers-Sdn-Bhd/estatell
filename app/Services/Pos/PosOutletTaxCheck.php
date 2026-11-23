<?php

namespace App\Services\Pos;

use App\Models\Property;

/**
 * Whether an enabled tax that is not included in the price is linked to a transaction code. POS receipts already hold
 * tax in their total, so such a tax would charge the guest twice. `SavePosOutlet` checks it when an outlet is saved;
 * `PostPosCharge` and `ResolvePendingPosPosting` check it again at posting time, because taxes change after an outlet
 * is saved. It reads only.
 */
class PosOutletTaxCheck
{
    public function hasExclusiveTax(Property $property, int $transactionCodeId): bool
    {
        return $property->taxes()
            ->where('enabled', true)
            ->where('is_inclusive', false)
            ->whereHas('applicableTransactionCodes', fn ($codes) => $codes->whereKey($transactionCodeId))
            ->exists();
    }
}
