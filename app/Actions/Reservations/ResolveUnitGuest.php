<?php

namespace App\Actions\Reservations;

use App\Actions\Guests\CreateGuest;
use App\Actions\Guests\UpdateGuest;
use App\Models\Guest;
use App\Models\Property;
use App\Models\User;

class ResolveUnitGuest
{
    public function __construct(
        private CreateGuest $createGuest,
        private UpdateGuest $updateGuest,
    ) {}

    /**
     * The guest of a row of the Guests tab: a new guest made from the typed profile (no `$guestId`), or the picked guest
     * of the property (not merged or anonymized) with its profile updated when `$updateProfile` is on (the tags are kept). It writes, so the caller
     * runs it inside its own transaction and rolls back on a refusal. Permissions are checked by the caller.
     *
     * @param  array<string, mixed>  $profile  Validated profile fields, the phone number in E.164.
     */
    public function __invoke(User $actor, Property $property, ?int $guestId, array $profile = [], bool $updateProfile = false): Guest|AttachGuestResult
    {
        if ($guestId === null) {
            if (trim((string) ($profile['full_name'] ?? '')) === '') {
                return AttachGuestResult::refused(BookingResult::REFUSAL_GUEST_NAME_REQUIRED);
            }

            return ($this->createGuest)($actor, $property, $profile);
        }

        $guest = Guest::query()
            ->where('property_id', $property->id)
            ->notMerged()
            ->whereNull('anonymized_at')
            ->find($guestId);

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

        return $guest;
    }
}
