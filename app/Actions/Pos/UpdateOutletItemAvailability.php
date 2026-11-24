<?php

namespace App\Actions\Pos;

use App\Enums\PosAvailability;
use App\Models\PosItemVariant;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Set what one outlet does with one variant: its availability (everywhere, POS only or not at all) and an optional price
 * override that replaces the variant's price at this outlet. The outlet and variant must be the property's, and a
 * price override cannot be negative (`price_invalid`). A refusal writes nothing.
 */
class UpdateOutletItemAvailability
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, PosItemVariant $variant, PosAvailability $availability, ?string $priceOverride = null): PosActionResult
    {
        if ($outlet->property_id !== $property->id || $variant->property_id !== $property->id) {
            return PosActionResult::refused('outlet_invalid');
        }

        if ($priceOverride !== null && BigDecimal::of($priceOverride)->isNegative()) {
            return PosActionResult::refused('price_invalid');
        }

        return DB::transaction(function () use ($actor, $property, $outlet, $variant, $availability, $priceOverride): PosActionResult {
            $row = PosOutletItem::query()->where('pos_outlet_id', $outlet->id)->where('pos_item_variant_id', $variant->id)->lockForUpdate()->first() ?? new PosOutletItem;
            $row->fill(['availability' => $availability, 'price_override' => $priceOverride]);
            $row->property_id = $property->id;
            $row->pos_outlet_id = $outlet->id;
            $row->pos_item_variant_id = $variant->id;
            $row->save();

            activity()->performedOn($row)->causedBy($actor)->event('pos.outlet_item_updated')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'availability' => $availability->value])
                ->log('POS outlet item updated');

            return PosActionResult::saved($row);
        });
    }
}
