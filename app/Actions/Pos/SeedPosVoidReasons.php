<?php

namespace App\Actions\Pos;

use App\Models\PosVoidReason;
use App\Models\Property;

/**
 * Give a property the common void reasons when it has none, so a new outlet can void a line or an order on day one.
 * Returns how many reasons were created; a property that already has reasons is left alone, so it is safe to run again.
 */
class SeedPosVoidReasons
{
    public function __invoke(Property $property): int
    {
        if (PosVoidReason::query()->where('property_id', $property->id)->exists()) {
            return 0;
        }

        $defaults = [
            ['Wrong item entered', false, true],
            ['Out of stock', false, true],
            ['Kitchen error', false, false],
            ['Guest changed mind', true, false],
        ];

        foreach ($defaults as $index => [$label, $requiresManagerPin, $returnStock]) {
            $reason = new PosVoidReason([
                'label' => $label,
                'requires_manager_pin' => $requiresManagerPin,
                'default_return_stock' => $returnStock,
                'sort_order' => $index,
                'active' => true,
            ]);
            $reason->property_id = $property->id;
            $reason->save();
        }

        return count($defaults);
    }
}
