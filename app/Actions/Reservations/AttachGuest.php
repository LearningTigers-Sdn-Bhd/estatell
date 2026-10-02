<?php

namespace App\Actions\Reservations;

use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\Guest;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AttachGuest
{
    /**
     * Add a guest as an occupant of a unit. The unit row is locked first, so two staff cannot fill a bed together.
     * The first guest of the unit becomes primary. A blacklisted guest is allowed. Every check runs before the first
     * write, so a refusal writes and logs nothing. The log holds ids only. See docs/12-reservations/06-reservation-page.md.
     */
    public function __invoke(User $actor, ReservationUnit $unit, Guest $guest): AttachGuestResult
    {
        return DB::transaction(function () use ($actor, $unit, $guest): AttachGuestResult {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);

            $found = Guest::query()
                ->where('property_id', $locked->property_id)
                ->notMerged()
                ->whereNull('anonymized_at')
                ->whereKey($guest->id)
                ->exists();

            if (! $found) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND);
            }

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn], true)) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_UNIT_NOT_OPEN);
            }

            if ($locked->guests()->where('guest_id', $guest->id)->exists()) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_ALREADY_ATTACHED);
            }

            $hasGuest = $locked->guests()->exists();

            if ($hasGuest && $locked->roomType->sell_unit === SellUnit::Bed) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_BED_UNIT_FULL);
            }

            $unitGuest = $locked->guests()->make(['guest_id' => $guest->id, 'is_primary' => ! $hasGuest]);
            $unitGuest->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.guest_attached')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'guest_id' => $guest->id,
                    'is_primary' => $unitGuest->is_primary,
                ])
                ->log('Guest attached to unit');

            return AttachGuestResult::attached($unitGuest);
        });
    }
}
