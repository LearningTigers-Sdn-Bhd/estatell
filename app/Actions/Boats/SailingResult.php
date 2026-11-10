<?php

namespace App\Actions\Boats;

use App\Models\BoatSailing;

/**
 * What the sailing Actions return: done, or the refusal code. A refusal writes and logs nothing. `sailing` is the stored
 * sailing the Action worked on (null when nothing was stored). `guests` counts the guests that block a refusal. `unitLabel`
 * names the room that a move refused ("Double · 104"). `postedFareKept` is true when a moved trip kept a posted fare.
 */
class SailingResult
{
    public const REFUSAL_SAILING_HAS_GUESTS = 'sailing_has_guests';

    public const REFUSAL_BOAT_UNAVAILABLE = 'boat_unavailable';

    public const REFUSAL_IN_CHARGE_REQUIRED = 'in_charge_required';

    public const REFUSAL_DATE_CLOSED = 'date_closed';

    public const REFUSAL_SAILING_NOT_FOUND = 'sailing_not_found';

    public const REFUSAL_SAILING_IS_MAIN = 'sailing_is_main';

    public const REFUSAL_TIMES_INVALID = 'times_invalid';

    public const REFUSAL_TARGET_MISMATCH = 'target_mismatch';

    private function __construct(
        public readonly ?string $refusal = null,
        public readonly ?BoatSailing $sailing = null,
        public readonly int $guests = 0,
        public readonly ?string $unitLabel = null,
        public readonly bool $postedFareKept = false,
    ) {}

    public static function done(?BoatSailing $sailing = null, bool $postedFareKept = false): self
    {
        return new self(sailing: $sailing, postedFareKept: $postedFareKept);
    }

    public static function refused(string $refusal, int $guests = 0, ?string $unitLabel = null): self
    {
        return new self($refusal, guests: $guests, unitLabel: $unitLabel);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
