<?php

namespace App\Actions\Guests;

use App\Models\Guest;
use App\Models\GuestNote;
use App\Models\User;

class AddGuestNote
{
    /**
     * Add an internal note to the guest. Notes cannot be edited or deleted.
     * Returns null when the guest was merged into another guest or anonymized.
     */
    public function __invoke(User $actor, Guest $guest, string $body): ?GuestNote
    {
        if ($guest->merged_into_id !== null || $guest->anonymized_at !== null) {
            return null;
        }

        $note = new GuestNote(['body' => $body]);
        $note->guest_id = $guest->id;
        $note->author_id = $actor->id;
        $note->save();

        return $note;
    }
}
