<?php

namespace App\Services\Kiosk;

use App\Models\ApiToken;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stops a kiosk token from guessing booking references. Every lookup takes one attempt from the token before it runs
 * (one atomic increment, so parallel requests cannot slip past the limit). A lookup that finds an open booking gives
 * its attempt back; any other lookup keeps it. So failures add up to `MAX_FAILURES` within `DECAY_SECONDS` and are
 * never reset by a success: knowing one valid reference does not help to guess others. Once the limit is passed the
 * token is locked out until the window runs out, whatever it asks, so the answer says nothing about whether a
 * reference exists. The count lives in the cache, per token, and holds no booking data.
 */
class KioskLookupLockout
{
    public const int MAX_FAILURES = 10;

    public const int DECAY_SECONDS = 300;

    /**
     * Take an attempt. False when the token is locked out.
     */
    public function attempt(ApiToken $token): bool
    {
        return RateLimiter::hit($this->key($token), self::DECAY_SECONDS) <= self::MAX_FAILURES;
    }

    public function retryAfter(ApiToken $token): int
    {
        return RateLimiter::availableIn($this->key($token));
    }

    /**
     * Give back the attempt of a lookup that found a booking.
     */
    public function refund(ApiToken $token): void
    {
        RateLimiter::decrement($this->key($token));
    }

    private function key(ApiToken $token): string
    {
        return 'kiosk-lookup-failures:'.$token->id;
    }
}
