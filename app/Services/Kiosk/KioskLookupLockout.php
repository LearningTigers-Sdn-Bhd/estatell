<?php

namespace App\Services\Kiosk;

use App\Models\Kiosk;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stops a kiosk from guessing booking references. Every lookup takes one attempt from the kiosk before it runs
 * (one atomic increment, so parallel requests cannot slip past the limit). A lookup that finds an open booking gives
 * its attempt back; any other lookup keeps it. So failures add up to `MAX_FAILURES` within `DECAY_SECONDS` and are
 * never reset by a success: knowing one valid reference does not help to guess others. Once the limit is passed the
 * kiosk is locked out until the window runs out, whatever it asks, so the answer says nothing about whether a
 * reference exists. The count lives in the cache, per kiosk, and holds no booking data.
 */
class KioskLookupLockout
{
    public const int MAX_FAILURES = 10;

    public const int DECAY_SECONDS = 300;

    /**
     * Take an attempt. False when the token is locked out.
     */
    public function attempt(Kiosk $kiosk): bool
    {
        return RateLimiter::hit($this->key($kiosk), self::DECAY_SECONDS) <= self::MAX_FAILURES;
    }

    public function retryAfter(Kiosk $kiosk): int
    {
        return RateLimiter::availableIn($this->key($kiosk));
    }

    /**
     * Give back the attempt of a lookup that found a booking. It never takes the count below zero: if the window ran
     * out since the attempt, `RateLimiter::decrement` would recreate the counter at -1 and hand out extra attempts.
     */
    public function refund(Kiosk $kiosk): void
    {
        if (RateLimiter::attempts($this->key($kiosk)) > 0) {
            RateLimiter::decrement($this->key($kiosk), self::DECAY_SECONDS);
        }
    }

    private function key(Kiosk $kiosk): string
    {
        return 'kiosk-lookup-failures:'.$kiosk->id;
    }
}
