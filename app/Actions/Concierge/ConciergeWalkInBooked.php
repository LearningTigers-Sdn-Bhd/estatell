<?php

namespace App\Actions\Concierge;

use App\Models\ReservationUnit;

/**
 * The outcome of booking a Concierge walk-in: the booked stay, or the refusal and nothing written. A refusal is one of
 * `walk_in_disabled`, `busy`, `no_room_available`, `nationality_required`, `identity_refused` or `no_match`, and for a quote link
 * also `quote_used` or `quote_expired`.
 */
final readonly class ConciergeWalkInBooked
{
    private function __construct(public ?ReservationUnit $stay, public ?string $refusal) {}

    public static function booked(ReservationUnit $stay): self
    {
        return new self($stay, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }
}
