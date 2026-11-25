<?php

namespace App\Actions\Pos;

use App\Enums\PosShiftStatus;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stop closing a shift: ClosingControl back to Open, so the register trades again. Any count so far is cleared (the
 * counts stay in the activity log). A refusal writes nothing.
 */
class CancelCloseShift
{
    public function __invoke(User $actor, Property $property, PosShift $shift): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $shift): PosActionResult {
            $shift = PosShift::query()->where('property_id', $property->id)->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift === null) {
                return PosActionResult::refused('shift_invalid');
            }

            if ($shift->status !== PosShiftStatus::ClosingControl) {
                return PosActionResult::refused('shift_not_closing');
            }

            $shift->forceFill(['status' => PosShiftStatus::Open, 'counted_cash' => null, 'cash_variance' => null, 'expected_cash' => null, 'recount_count' => 0])->save();

            activity()->performedOn($shift)->causedBy($actor)->event('pos.shift_closing_cancelled')
                ->withProperties(['property_id' => $property->id])
                ->log('POS shift closing cancelled');

            return PosActionResult::saved($shift);
        });
    }
}
