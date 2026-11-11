<?php

namespace App\Services\Concierge;

use App\Models\Property;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Stops anyone from guessing booking codes on the lobby page. A lookup takes one attempt from two counters before it
 * runs: one per address and property, and one per booking code, so spreading guesses over many addresses still locks
 * the code. A lookup that finds the stay gives both attempts back. Once a counter is passed the answer is `rate_limited`
 * whatever the guest typed, so it never says whether a code exists. The counts live in the cache and hold no booking data.
 */
class ConciergeLookupLockout
{
    public const int MAX_PER_ADDRESS = 8;

    public const int ADDRESS_DECAY_SECONDS = 300;

    public const int MAX_PER_CODE = 5;

    public const int CODE_DECAY_SECONDS = 900;

    /** Stays one address may open in `FOUND_DECAY_SECONDS`. A lobby shares one address, so it is generous; it stops a scan down the booking codes. */
    public const int MAX_FOUND_PER_ADDRESS = 15;

    public const int FOUND_DECAY_SECONDS = 600;

    /**
     * Count a stay this address opened. Booking codes run in order, so a scan finds a stay almost every time and the
     * refund of `attempt` would never slow it. False once the address has opened too many.
     */
    public function found(Property $property, string $address): bool
    {
        return RateLimiter::hit($this->foundKey($property, $address), self::FOUND_DECAY_SECONDS) <= self::MAX_FOUND_PER_ADDRESS;
    }

    public function foundRetryAfter(Property $property, string $address): int
    {
        return RateLimiter::availableIn($this->foundKey($property, $address));
    }

    private function foundKey(Property $property, string $address): string
    {
        return 'concierge-lookup-found:'.$property->id.':'.sha1($address);
    }

    public function attempt(Property $property, string $address, string $reference): bool
    {
        $byAddress = RateLimiter::hit($this->addressKey($property, $address), self::ADDRESS_DECAY_SECONDS) <= self::MAX_PER_ADDRESS;
        $byCode = RateLimiter::hit($this->codeKey($property, $reference), self::CODE_DECAY_SECONDS) <= self::MAX_PER_CODE;

        return $byAddress && $byCode;
    }

    public function retryAfter(Property $property, string $address, string $reference): int
    {
        return max(RateLimiter::availableIn($this->addressKey($property, $address)), RateLimiter::availableIn($this->codeKey($property, $reference)));
    }

    /**
     * Give back the attempts of a lookup that found its stay. They never go below zero: after the window ran out,
     * `RateLimiter::decrement` would recreate the counter at -1 and hand out extra attempts.
     */
    public function refund(Property $property, string $address, string $reference): void
    {
        foreach ([[$this->addressKey($property, $address), self::ADDRESS_DECAY_SECONDS], [$this->codeKey($property, $reference), self::CODE_DECAY_SECONDS]] as [$key, $decay]) {
            if (RateLimiter::attempts($key) > 0) {
                RateLimiter::decrement($key, $decay);
            }
        }
    }

    private function addressKey(Property $property, string $address): string
    {
        return 'concierge-lookup-address:'.$property->id.':'.sha1($address);
    }

    private function codeKey(Property $property, string $reference): string
    {
        return 'concierge-lookup-code:'.$property->id.':'.sha1(mb_strtolower(trim($reference)));
    }
}
