<?php

namespace App\Actions\Pos;

use App\Features\NativePos;
use App\Models\PosStaffPin;
use App\Models\User;
use App\Services\Pos\PosPinHasher;
use App\Services\Pos\PosPinPolicy;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Set or change the signed-in staff member's own POS PIN. Managers can only revoke a PIN (`RevokePosStaffPin`), never set
 * one for someone else. The PIN is 4 to 6 digits, and at least 6 for a user holding an elevated POS permission. Returns
 * null when saved, or a refusal code (`native_pos_disabled`, `pin_invalid`, `pin_too_short_for_role`); a refusal writes nothing.
 */
class SetPosStaffPin
{
    public function __construct(private PosPinHasher $hasher, private PosPinPolicy $policy) {}

    public function __invoke(User $user, string $pin): ?string
    {
        $property = $user->property;

        if ($property === null || ! Feature::for($property)->active(NativePos::class)) {
            return 'native_pos_disabled';
        }

        if (! $this->policy->isWellFormed($pin)) {
            return 'pin_invalid';
        }

        if (strlen($pin) < $this->policy->minimumLength($user)) {
            return 'pin_too_short_for_role';
        }

        DB::transaction(function () use ($user, $property, $pin): void {
            $staffPin = PosStaffPin::query()->where('property_id', $property->id)->where('user_id', $user->id)->first() ?? new PosStaffPin;
            $staffPin->fill(['pin_hash' => $this->hasher->make($pin), 'pin_length' => strlen($pin), 'active' => true]);
            $staffPin->property_id = $property->id;
            $staffPin->user_id = $user->id;
            $staffPin->save();

            activity()->performedOn($staffPin)->causedBy($user)->event('pos.pin_set')->log('POS PIN set');
        });

        return null;
    }
}
