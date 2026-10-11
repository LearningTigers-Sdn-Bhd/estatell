<?php

namespace App\Actions\Reservations;

use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class AddGuestToUnit
{
    public function __construct(
        private ResolveUnitGuest $resolveGuest,
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
            $guest = ($this->resolveGuest)($actor, $property, $guestId, $profile, $updateProfile);
            $result = $guest instanceof AttachGuestResult ? $guest : ($this->attachGuest)($actor, $unit, $guest);
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
}
