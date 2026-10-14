<?php

namespace App\Services\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RoomRevenuePolicy;

/**
 * Which taxes a room revenue code uses. ROOM holds the Taxes tab selection. A policy that follows the Taxes tab
 * uses that selection, and a policy with its own taxes uses the taxes linked to its code. It reads only.
 */
class RoomRevenueTaxIds
{
    /** @return list<int> */
    public function forRoom(Property $property): array
    {
        return $this->linkedTo($property, SystemTransactionCode::RoomRevenue);
    }

    /** @return list<int> */
    public function forPolicy(Property $property, RoomRevenuePolicy $policy): array
    {
        return $policy->own_taxes ? $this->linkedTo($property, $policy->code) : $this->forRoom($property);
    }

    /** @return list<int> */
    public function linkedTo(Property $property, SystemTransactionCode $code): array
    {
        $transactionCode = $property->transactionCodes()->where('system_key', $code->value)->with('applicableTaxes')->first();

        return array_values(($transactionCode->applicableTaxes ?? collect())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->all());
    }
}
