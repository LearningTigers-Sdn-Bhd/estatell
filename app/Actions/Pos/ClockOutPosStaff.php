<?php

namespace App\Actions\Pos;

use App\Models\PosTimeClock;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clock a staff member out, closing their one open clock. Nobody clocked in is refused (`not_clocked_in`). A refusal writes
 * nothing.
 */
class ClockOutPosStaff
{
    public function __invoke(User $actor, Property $property): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property): PosActionResult {
            $clock = PosTimeClock::query()->where('property_id', $property->id)->where('user_id', $actor->id)->whereNull('clocked_out_at')->lockForUpdate()->first();

            if ($clock === null) {
                return PosActionResult::refused('not_clocked_in');
            }

            $clock->forceFill(['clocked_out_at' => now()])->save();

            activity()->performedOn($clock)->causedBy($actor)->event('pos.clocked_out')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $clock->pos_outlet_id])
                ->log('POS staff clocked out');

            return PosActionResult::saved($clock);
        });
    }
}
