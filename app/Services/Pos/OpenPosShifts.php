<?php

namespace App\Services\Pos;

use App\Models\Property;

/**
 * Whether a property still has a register shift that is not closed. Native POS cannot be turned off while one exists,
 * because staff could no longer open the terminal to close it.
 */
class OpenPosShifts
{
    public function exist(Property $property): bool
    {
        // ponytail: `pos_shifts` arrives in Phase 1 commit 4, which replaces this with a non-Closed shift query.
        return false;
    }
}
