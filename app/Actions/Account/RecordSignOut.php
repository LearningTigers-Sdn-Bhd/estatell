<?php

namespace App\Actions\Account;

use App\Models\SignInEvent;
use Illuminate\Contracts\Session\Session;

/**
 * Marks this browser session's sign-in row as signed out. See docs/01-platform/07-sign-in-log.md.
 */
class RecordSignOut
{
    /**
     * Returns false without writing when the session has no sign-in row or it is already signed out.
     */
    public function __invoke(Session $session): bool
    {
        $eventId = $session->pull(SignInEvent::SESSION_KEY);

        if (! is_int($eventId)) {
            return false;
        }

        return SignInEvent::query()->whereKey($eventId)->whereNull('signed_out_at')->update(['signed_out_at' => now(), 'last_active_at' => now()]) > 0;
    }
}
