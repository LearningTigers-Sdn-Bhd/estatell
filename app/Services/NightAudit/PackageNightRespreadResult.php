<?php

namespace App\Services\NightAudit;

use App\Models\UnitNight;
use Brick\Money\Money;

/**
 * The uncharged nights and their new prices, the charged nights and stored totals, or a refusal code.
 */
class PackageNightRespreadResult
{
    /**
     * @param  list<UnitNight>  $nights  The nights not charged yet, in date order
     * @param  list<Money>  $amounts  Their new amounts, in the same order
     * @param  list<UnitNight>  $chargedNights  The charged nights left as they are; empty for a whole-stay spread
     * @param  bool  $wholeStay  The uncharged nights could not cover their inclusions, so every night is spread again
     * @param  list<int>  $chargedNightIds  In a whole-stay spread, the nights in `$nights` that are already charged
     */
    public function __construct(
        public readonly array $nights = [],
        public readonly array $amounts = [],
        public readonly array $chargedNights = [],
        public readonly ?Money $total = null,
        public readonly ?Money $remaining = null,
        public readonly ?string $refusal = null,
        public readonly bool $wholeStay = false,
        public readonly array $chargedNightIds = [],
    ) {}

    public function isCharged(UnitNight $night): bool
    {
        return in_array($night->id, $this->chargedNightIds, true);
    }
}
