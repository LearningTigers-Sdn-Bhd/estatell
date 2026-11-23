<?php

namespace App\Actions\Concierge;

/**
 * What `OpenConciergeStay` returns: the token of the stay's link, or a refusal. `not_found` is the same answer for a
 * wrong code, a code that matches more than one room and a stay outside the property, so it never says which part was wrong.
 */
final readonly class ConciergeLookupResult
{
    private function __construct(
        public ?string $token,
        public ?string $refusal,
        public int $retryAfter = 0,
        public bool $checkedIn = false,
    ) {}

    public static function found(string $token, bool $checkedIn = false): self
    {
        return new self($token, null, 0, $checkedIn);
    }

    public static function refused(string $refusal, int $retryAfter = 0): self
    {
        return new self(null, $refusal, $retryAfter);
    }
}
