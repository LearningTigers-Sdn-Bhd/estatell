<?php

namespace App\Services\Kiosk;

use App\Models\ApiToken;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stops a kiosk token from guessing booking references. Every lookup that does not find an open booking counts as a
 * failure; after `MAX_FAILURES` within `DECAY_SECONDS` the token is locked out until the window runs out, whatever it
 * asks, so a locked answer says nothing about whether a reference exists. A found booking clears the count. The count
 * lives in the cache, per token, and holds no booking data.
 */
class KioskLookupLockout
{
    public const int MAX_FAILURES = 10;

    public const int DECAY_SECONDS = 300;

    public function isLocked(ApiToken $token): bool
    {
        return RateLimiter::tooManyAttempts($this->key($token), self::MAX_FAILURES);
    }

    public function retryAfter(ApiToken $token): int
    {
        return RateLimiter::availableIn($this->key($token));
    }

    public function recordFailure(ApiToken $token): void
    {
        RateLimiter::hit($this->key($token), self::DECAY_SECONDS);
    }

    public function clear(ApiToken $token): void
    {
        RateLimiter::clear($this->key($token));
    }

    private function key(ApiToken $token): string
    {
        return 'kiosk-lookup-failures:'.$token->id;
    }
}
