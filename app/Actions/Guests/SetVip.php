<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetVip
{
    /**
     * Mark the guest as VIP or take the mark off. Returns false when nothing would change or the profile is closed.
     */
    public function __invoke(User $actor, Guest $guest, bool $isVip): bool
    {
        return DB::transaction(function () use ($actor, $guest, $isVip): bool {
            $guest = Guest::query()->lockForUpdate()->findOrFail($guest->id);

            if ($guest->is_vip === $isVip || $guest->merged_into_id !== null || $guest->anonymized_at !== null) {
                return false;
            }

            $guest->is_vip = $isVip;
            $guest->save();

            activity()
                ->performedOn($guest)
                ->causedBy($actor)
                ->event($isVip ? 'guest.vip_set' : 'guest.vip_removed')
                ->withProperties(['property_id' => $guest->property_id])
                ->log($isVip ? 'Guest marked VIP' : 'Guest VIP mark removed');

            return true;
        });
    }
}
