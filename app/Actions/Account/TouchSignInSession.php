<?php

namespace App\Actions\Account;

use App\Models\SignInEvent;
use Illuminate\Contracts\Session\Session;

/**
 * Moves the last active time of this browser session's sign-in row forward, at most once a minute, so a busy page does
 * not write on every request. See docs/01-platform/07-sign-in-log.md.
 */
class TouchSignInSession
{
    /**
     * Seconds between two writes for the same session.
     */
    public const int EVERY_SECONDS = 60;

    /**
     * The session key that holds when the row was last written, as a Unix timestamp.
     */
    public const string TOUCHED_AT_KEY = 'sign_in_log.touched_at';

    /**
     * Returns false without writing when the session has no sign-in row or was touched less than a minute ago.
     */
    public function __invoke(Session $session): bool
    {
        $eventId = $session->get(SignInEvent::SESSION_KEY);

        if (! is_int($eventId) || (int) $session->get(self::TOUCHED_AT_KEY, 0) > now()->subSeconds(self::EVERY_SECONDS)->getTimestamp()) {
            return false;
        }

        $session->put(self::TOUCHED_AT_KEY, now()->getTimestamp());

        return SignInEvent::query()->whereKey($eventId)->whereNull('signed_out_at')->update(['last_active_at' => now()]) > 0;
    }
}
