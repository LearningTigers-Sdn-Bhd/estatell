<?php

namespace App\Actions\Pos;

use App\Enums\PosAvailability;
use App\Models\PosModifierOption;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The modifier-level 86: switch one choice (oat milk, extra shot) to everywhere, POS only or not available. It
 * takes effect on every item that offers the group. The option must be the property's. A refusal writes nothing.
 */
class ToggleModifierOptionAvailability
{
    public function __invoke(User $actor, Property $property, PosModifierOption $option, PosAvailability $availability): PosActionResult
    {
        if ($option->property_id !== $property->id) {
            return PosActionResult::refused('option_invalid');
        }

        return DB::transaction(function () use ($actor, $property, $option, $availability): PosActionResult {
            $option = PosModifierOption::query()->whereKey($option->id)->lockForUpdate()->firstOrFail();
            $option->forceFill(['availability' => $availability])->save();

            activity()->performedOn($option)->causedBy($actor)->event('pos.modifier_option_toggled')
                ->withProperties(['property_id' => $property->id, 'availability' => $availability->value])
                ->log('POS modifier option availability changed');

            return PosActionResult::saved($option);
        });
    }
}
