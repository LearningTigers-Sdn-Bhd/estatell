<?php

namespace App\Actions\Reservations;

use App\Enums\RegistrationCardStatus;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\RegistrationCard;
use App\Models\ReservationUnit;
use App\Models\UnitGuest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReplaceUnitGuest
{
    public function __construct(
        private ResolveUnitGuest $resolveGuest,
        private AttachGuest $attachGuest,
    ) {}

    /**
     * Put another person in place of a guest on a unit. The `unit_guests` row keeps its place and `is_primary`; only
     * `guest_id` changes. The new guest is a new guest or a picked one (see `ResolveUnitGuest`). A Draft registration
     * card of the old guest is deleted with the change; a signed or void card refuses it, because the card keeps the
     * old guest's snapshot. Nothing is posted or repriced, and a folio points at a guest, not at this row. Every check
     * runs before the first write, so a refusal writes and logs nothing, and a new guest rolls back too. See
     * docs/12-reservations/06-reservation-page.md#replace-and-remove-a-guest.
     *
     * @param  array<string, mixed>  $profile  Validated profile fields, the phone number in E.164.
     */
    public function __invoke(User $actor, Property $property, ReservationUnit $unit, UnitGuest $occupant, ?int $guestId, array $profile = [], bool $updateProfile = false): AttachGuestResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $property, $unit, $occupant, $guestId, $profile, $updateProfile);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($result->isRefused()) {
            DB::rollBack();

            return $result;
        }

        DB::commit();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function run(User $actor, Property $property, ReservationUnit $unit, UnitGuest $occupant, ?int $guestId, array $profile, bool $updateProfile): AttachGuestResult
    {
        $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
        $row = $locked->guests()->whereKey($occupant->id)->first();

        if ($row === null) {
            return AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND);
        }

        if (! in_array($locked->status, [UnitStatus::Confirmed, UnitStatus::CheckedIn, UnitStatus::CheckedOut], true)) {
            return AttachGuestResult::refused(AttachGuestResult::REFUSAL_UNIT_NOT_OPEN);
        }

        $cards = RegistrationCard::query()->where('unit_guest_id', $row->id)->get();

        if ($cards->contains(fn (RegistrationCard $card): bool => $card->status !== RegistrationCardStatus::Draft)) {
            return AttachGuestResult::refused(AttachGuestResult::REFUSAL_HAS_REGISTRATION_CARD);
        }

        $guest = ($this->resolveGuest)($actor, $property, $guestId, $profile, $updateProfile);

        if ($guest instanceof AttachGuestResult) {
            return $guest;
        }

        if ($locked->guests()->where('guest_id', $guest->id)->exists()) {
            return AttachGuestResult::refused(AttachGuestResult::REFUSAL_ALREADY_ATTACHED);
        }

        $previousGuestId = $row->guest_id;
        $deletedCards = $cards->pluck('id')->all();

        $cards->each(fn (RegistrationCard $card) => $card->delete());

        $row->guest_id = $guest->id;
        $row->save();

        activity()
            ->performedOn($locked->reservation)
            ->causedBy($actor)
            ->event('reservation.guest_replaced')
            ->withProperties([
                'property_id' => $locked->property_id,
                'reservation_unit_id' => $locked->id,
                'previous_guest_id' => $previousGuestId,
                'guest_id' => $guest->id,
                'is_primary' => $row->is_primary,
                'deleted_draft_card_ids' => $deletedCards,
            ])
            ->log('Guest replaced on unit');

        return AttachGuestResult::attached($row, $this->attachGuest->taxWarning($locked, $guest, $row->is_primary));
    }
}
