<?php

namespace App\Actions\Reservations;

use App\Enums\RegistrationCardStatus;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Models\RegistrationCard;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use App\Services\Reservations\UnitCapacity;
use Illuminate\Support\Facades\DB;

class MoveGuestToUnit
{
    public function __construct(private AttachGuest $attachGuest, private UnitCapacity $capacity) {}

    /**
     * Move a guest to another unit of the same reservation. The `unit_guests` row keeps its guest and changes unit.
     * The guest is primary on the new unit when it has no guest yet; when the guest was primary on the old unit, the
     * oldest other guest there becomes primary. A Draft registration card is deleted with the move; a signed or void
     * card refuses it, because the card keeps the guest's snapshot and points at the row. Nothing is posted or repriced,
     * and a folio points at a guest, not at this row. A full unit refuses the guest (`bed_unit_full` or `unit_full`,
     * see `UnitCapacity`). Both unit rows are locked in id order, and every check runs
     * before the first write, so a refusal writes and logs nothing. See
     * docs/12-reservations/06-reservation-page.md#move-a-guest-to-another-unit.
     */
    public function __invoke(User $actor, ReservationUnit $from, UnitGuest $occupant, ReservationUnit $target): AttachGuestResult
    {
        return DB::transaction(function () use ($actor, $from, $occupant, $target): AttachGuestResult {
            $locked = ReservationUnit::query()
                ->whereKey([$from->id, $target->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $locked->get($from->id);
            $destination = $locked->get($target->id);
            $row = $source?->guests()->whereKey($occupant->id)->first();

            if ($row === null || $destination === null || $source->reservation_id !== $destination->reservation_id) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND);
            }

            $open = [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut];

            if (! in_array($source->status, $open, true) || ! in_array($destination->status, $open, true)) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_UNIT_NOT_OPEN);
            }

            $cards = RegistrationCard::query()->where('unit_guest_id', $row->id)->get();

            if ($cards->contains(fn (RegistrationCard $card): bool => $card->status !== RegistrationCardStatus::Draft)) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_HAS_REGISTRATION_CARD);
            }

            if ($destination->guests()->where('guest_id', $row->guest_id)->exists()) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_ALREADY_ATTACHED);
            }

            $destinationGuests = $destination->guests()->count();
            $destinationHasGuest = $destinationGuests > 0;

            if ($destinationGuests >= $this->capacity->of($destination)) {
                return AttachGuestResult::refused($destination->roomType->sell_unit === SellUnit::Bed ? AttachGuestResult::REFUSAL_BED_UNIT_FULL : AttachGuestResult::REFUSAL_UNIT_FULL);
            }

            $deletedCards = $cards->pluck('id')->all();
            $cards->each(fn (RegistrationCard $card) => $card->delete());

            $wasPrimary = $row->is_primary;

            $row->forceFill(['reservation_unit_id' => $destination->id, 'is_primary' => ! $destinationHasGuest])->save();

            $promoted = $wasPrimary
                ? $source->guests()->orderBy('id')->first()
                : null;

            $promoted?->forceFill(['is_primary' => true])->save();

            activity()
                ->performedOn($source->reservation)
                ->causedBy($actor)
                ->event('reservation.guest_moved')
                ->withProperties([
                    'property_id' => $source->property_id,
                    'from_reservation_unit_id' => $source->id,
                    'reservation_unit_id' => $destination->id,
                    'guest_id' => $row->guest_id,
                    'is_primary' => $row->is_primary,
                    'promoted_guest_id' => $promoted?->guest_id,
                    'deleted_draft_card_ids' => $deletedCards,
                ])
                ->log('Guest moved to another unit');

            return AttachGuestResult::attached($row, $this->attachGuest->taxWarning($destination, $row->guest, $row->is_primary));
        });
    }
}
