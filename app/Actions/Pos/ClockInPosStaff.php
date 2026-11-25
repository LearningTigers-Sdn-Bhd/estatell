<?php

namespace App\Actions\Pos;

use App\Models\PosOutlet;
use App\Models\PosTimeClock;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Clock a staff member in at an outlet. A person who is already clocked in anywhere is refused (`already_clocked_in`), so one
 * shift of work is never counted twice. The outlet must belong to the property and be Native POS (`outlet_invalid`). A
 * refusal writes nothing.
 */
class ClockInPosStaff
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet): PosActionResult
    {
        if ($outlet->property_id !== $property->id || ! $outlet->native_pos_enabled || $actor->property_id !== $property->id) {
            return PosActionResult::refused('outlet_invalid');
        }

        try {
            return DB::transaction(function () use ($actor, $property, $outlet): PosActionResult {
                if (PosTimeClock::query()->where('user_id', $actor->id)->whereNull('clocked_out_at')->lockForUpdate()->exists()) {
                    return PosActionResult::refused('already_clocked_in');
                }

                $clock = new PosTimeClock;
                $clock->property_id = $property->id;
                $clock->pos_outlet_id = $outlet->id;
                $clock->user_id = $actor->id;
                $clock->clocked_in_at = now();
                $clock->save();

                activity()->performedOn($clock)->causedBy($actor)->event('pos.clocked_in')
                    ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id])
                    ->log('POS staff clocked in');

                return PosActionResult::saved($clock);
            });
        } catch (UniqueConstraintViolationException) {
            return PosActionResult::refused('already_clocked_in');
        }
    }
}
