<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnblacklistGuest
{
    /**
     * Remove the guest from the blacklist. Returns false when the guest is not blacklisted.
     */
    public function __invoke(User $actor, Guest $guest): bool
    {
        return DB::transaction(function () use ($actor, $guest): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->blacklisted_at === null) {
                return false;
            }

            $guest->blacklisted_at = null;
            $guest->blacklist_reason = null;
            $guest->save();

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event('guest.unblacklisted')
                ->withProperties(['property_id' => $guest->property_id])
                ->log('Guest removed from the blacklist');

            return true;
        });
    }
}
