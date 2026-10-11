<?php

namespace App\Actions\Reservations;

use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\UpdateGuest;
use App\Models\Guest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class AddGuestToUnit
{
    public function __construct(
        private CreateGuest $createGuest,
        private UpdateGuest $updateGuest,
        private AttachGuest $attachGuest,
    ) {}

    /**
     * Put a guest on a unit from a draft row of the Guests tab, in one transaction: create the guest (no `$guestId`),
     * or update the picked guest's profile when `$updateProfile` is on, then run `AttachGuest`. A refusal at any step
     * rolls everything back, including a new guest. The tags of an updated guest are kept. Permissions for creating or
     * updating a guest are checked by the caller. See docs/12-reservations/06-reservation-page.md.
     *
     * @param  array<string, mixed>  $profile  Validated profile fields, the phone number in E.164.
     */
    public function __invoke(User $actor, Property $property, ReservationUnit $unit, ?int $guestId, array $profile = [], bool $updateProfile = false): AttachGuestResult
    {
        DB::beginTransaction();

        try {
            $result = $this->run($actor, $property, $unit, $guestId, $profile, $updateProfile);
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
    private function run(User $actor, Property $property, ReservationUnit $unit, ?int $guestId, array $profile, bool $updateProfile): AttachGuestResult
    {
        if ($guestId === null) {
            if (trim((string) ($profile['full_name'] ?? '')) === '') {
                return AttachGuestResult::refused(BookingResult::REFUSAL_GUEST_NAME_REQUIRED);
            }

            $guest = ($this->createGuest)($actor, $property, $profile);
        } else {
            $guest = Guest::query()->where('property_id', $property->id)->find($guestId);

            if ($guest === null) {
                return AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND);
            }

            if ($updateProfile && $profile !== []) {
                $tags = $guest->tags()->pluck('name')->all();

                if (! ($this->updateGuest)($actor, $guest, $profile, $tags)) {
                    return AttachGuestResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND);
                }

                $guest->refresh();
            }
        }

        return ($this->attachGuest)($actor, $unit, $guest);
    }
}
