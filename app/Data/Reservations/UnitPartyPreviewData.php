<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Change guests Dialog shows for the party typed: each night with its price now and after, the room total
 * before and after, whether the booking came from a channel (its price stays), and the refusal message, if any.
 */
class UnitPartyPreviewData extends Data
{
    /**
     * @param  list<UnitPartyNightData>  $nights
     * @param  list<UnitPartyAddOnData>  $addOns  The charged per-guest add-ons to correct.
     */
    public function __construct(
        public array $nights,
        public string $before,
        public string $after,
        public string $difference,
        public bool $fromChannel,
        public bool $hasCharged,
        public ?string $error,
        public array $addOns = [],
    ) {}
}
