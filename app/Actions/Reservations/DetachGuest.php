<?php

namespace App\Actions\Reservations;

use App\Enums\RegistrationCardStatus;
use App\Enums\UnitStatus;
use App\Models\RegistrationCard;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DetachGuest
{
    /**
     * Take a guest off a unit. When the guest was primary, the oldest other guest of the unit becomes primary. A Draft
     * registration card is deleted with the row; a signed or void card refuses the removal, because the card keeps the
     * guest's snapshot and points at the row. Nothing is posted or repriced. Returns the refusal code, or null once the
     * guest is removed; a refusal writes and logs nothing. See
     * docs/12-reservations/06-reservation-page.md#replace-and-remove-a-guest.
     */
    public function __invoke(User $actor, ReservationUnit $unit, UnitGuest $occupant): ?string
    {
        return DB::transaction(function () use ($actor, $unit, $occupant): ?string {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $row = $locked->guests()->whereKey($occupant->id)->first();

            if ($row === null) {
                return AttachGuestResult::REFUSAL_GUEST_NOT_FOUND;
            }

            if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
                return AttachGuestResult::REFUSAL_UNIT_NOT_OPEN;
            }

            $cards = RegistrationCard::query()->where('unit_guest_id', $row->id)->get();

            if ($cards->contains(fn (RegistrationCard $card): bool => $card->status !== RegistrationCardStatus::Draft)) {
                return AttachGuestResult::REFUSAL_HAS_REGISTRATION_CARD;
            }

            $deletedCards = $cards->pluck('id')->all();
            $cards->each(fn (RegistrationCard $card) => $card->delete());

            $row->delete();

            $promoted = $row->is_primary
                ? $locked->guests()->orderBy('id')->first()
                : null;

            $promoted?->forceFill(['is_primary' => true])->save();

            activity()
                ->performedOn($locked->reservation)
                ->causedBy($actor)
                ->event('reservation.guest_detached')
                ->withProperties([
                    'property_id' => $locked->property_id,
                    'reservation_unit_id' => $locked->id,
                    'guest_id' => $row->guest_id,
                    'was_primary' => $row->is_primary,
                    'promoted_guest_id' => $promoted?->guest_id,
                    'deleted_draft_card_ids' => $deletedCards,
                ])
                ->log('Guest removed from unit');

            return null;
        });
    }
}
