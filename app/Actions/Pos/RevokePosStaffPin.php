<?php

namespace App\Actions\Pos;

use App\Models\PosStaffPin;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A manager clears a staff member's POS PIN so they must set a new one. Returns false when the user has no PIN in this property.
 */
class RevokePosStaffPin
{
    public function __invoke(User $actor, Property $property, User $target): bool
    {
        return DB::transaction(function () use ($actor, $property, $target): bool {
            $staffPin = PosStaffPin::query()->where('property_id', $property->id)->where('user_id', $target->id)->first();

            if ($staffPin === null) {
                return false;
            }

            $staffPin->delete();

            activity()->performedOn($property)->causedBy($actor)->event('pos.pin_revoked')->withProperties(['user_id' => $target->id])->log('POS PIN revoked');

            return true;
        });
    }
}
