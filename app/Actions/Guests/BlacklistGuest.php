<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BlacklistGuest
{
    /**
     * Blacklist the guest with a reason. Returns false when the guest is already blacklisted or the profile is closed.
     */
    public function __invoke(User $actor, Guest $guest, string $reason): bool
    {
        return DB::transaction(function () use ($actor, $guest, $reason): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->blacklisted_at !== null || $guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return false;
            }

            $guest->blacklisted_at = now();
            $guest->blacklist_reason = $reason;
            $guest->save();

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.blacklisted')
                ->withProperties(['property_id' => $guest->property_id, 'reason' => $reason])
                ->log('Guest blacklisted');

            return true;
        });
    }
}
