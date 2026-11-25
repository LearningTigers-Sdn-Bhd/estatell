<?php

namespace App\Services\Pos;

use App\Enums\PosShiftStatus;
use App\Models\PosShift;
use App\Models\Property;

/**
 * Whether a property still has a register shift that is not closed. Native POS cannot be turned off while one exists,
 * because staff could no longer open the terminal to close it.
 */
class OpenPosShifts
{
    public function exist(Property $property): bool
    {
        return PosShift::query()->where('property_id', $property->id)->where('status', '!=', PosShiftStatus::Closed->value)->exists();
    }
}
