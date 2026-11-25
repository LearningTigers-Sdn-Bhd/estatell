<?php

namespace App\Actions\Pos;

use App\Enums\PosDiningKind;
use App\Models\PosDiningOption;
use App\Models\PosOutlet;

/**
 * Give a native POS outlet the Malaysian hotel dining options when it has none: Dine-In (default), Takeaway, Room
 * Service and Poolside. Service charge is not part of the SST base (RMCD Service Tax Policy No. 3/2024, Amendment No. 1).
 * Returns how many options were created; an outlet that already has options is left alone, so it is safe to run again.
 * See docs/34-native-pos/02-menu-catalog.md#7-dining-options--defaults.
 */
class SeedPosDiningOptions
{
    public function __invoke(PosOutlet $outlet): int
    {
        if ($outlet->diningOptions()->exists()) {
            return 0;
        }

        $defaults = [
            ['Dine-In', PosDiningKind::DineIn, true, '10.00', true],
            ['Takeaway', PosDiningKind::Takeaway, false, '0.00', false],
            ['Room Service', PosDiningKind::RoomService, true, '10.00', false],
            ['Poolside', PosDiningKind::Poolside, true, '10.00', false],
        ];

        foreach ($defaults as [$name, $kind, $applyServiceCharge, $rate, $isDefault]) {
            $option = new PosDiningOption([
                'name' => $name,
                'kind' => $kind,
                'apply_service_charge' => $applyServiceCharge,
                'service_charge_rate' => $rate,
                'service_charge_taxable' => false,
                'packaging_fee' => '0.00',
                'is_default' => $isDefault,
                'active' => true,
            ]);
            $option->property_id = $outlet->property_id;
            $option->pos_outlet_id = $outlet->id;
            $option->save();
        }

        return count($defaults);
    }
}
