<?php

namespace App\Actions\Boats;

use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\User;

class SetCharterChargeItem
{
    /**
     * Pick the charge item a Charter boat trip adds once, or none (null). It must be an active charge item of the
     * property with a fixed price; returns false otherwise and writes nothing. Logs `boats.charter_item_set`.
     */
    public function __invoke(User $actor, Property $property, ?int $chargeItemId): bool
    {
        $settings = $property->settings;

        if ($settings === null) {
            return false;
        }

        if ($chargeItemId !== null && ! ChargeItem::query()->where('property_id', $property->id)->active()->whereNotNull('default_amount')->whereKey($chargeItemId)->exists()) {
            return false;
        }

        if ($settings->charter_charge_item_id === $chargeItemId) {
            return true;
        }

        $settings->update(['charter_charge_item_id' => $chargeItemId]);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('boats.charter_item_set')
            ->withProperties(['property_id' => $property->id, 'charge_item_id' => $chargeItemId])
            ->log('Charter boat charge set');

        return true;
    }
}
