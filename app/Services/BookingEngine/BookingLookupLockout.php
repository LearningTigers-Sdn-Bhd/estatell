<?php

namespace App\Services\BookingEngine;

use App\Models\Property;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stops one guest address from guessing booking codes at one property. Every lookup takes an attempt before it runs (one
 * atomic increment, so parallel requests cannot slip past the limit). A lookup that finds a booking gives its attempt back,
 * and never resets the failures. Once locked out, every lookup from that address answers the same, so the answer says
 * nothing about whether a code exists. It does not touch the booking or the emailed link. The key holds only a hash of the
 * address. See docs/29-booking-engine/02-api.md#find-a-booking.
 */
class BookingLookupLockout
{
    public const int MAX_FAILURES = 10;

    public const int DECAY_SECONDS = 300;

    /**
     * Take an attempt. False when the address is locked out.
     */
    public function attempt(Property $property, string $guestIp): bool
    {
        return RateLimiter::hit($this->key($property, $guestIp), self::DECAY_SECONDS) <= self::MAX_FAILURES;
    }

    public function retryAfter(Property $property, string $guestIp): int
    {
        return RateLimiter::availableIn($this->key($property, $guestIp));
    }

    /**
     * Give back the attempt of a lookup that found a booking. It never takes the count below zero: if the window ran out
     * since the attempt, `RateLimiter::decrement` would recreate the counter at -1 and hand out extra attempts.
     */
    public function refund(Property $property, string $guestIp): void
    {
        $key = $this->key($property, $guestIp);

        if (RateLimiter::attempts($key) > 0) {
            RateLimiter::decrement($key, self::DECAY_SECONDS);
        }
    }

    private function key(Property $property, string $guestIp): string
    {
        return 'booking-lookup-failures:'.$property->id.':'.hash_hmac('sha256', $guestIp, (string) config('app.key'));
    }
}
