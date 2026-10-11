<?php

namespace App\Services\Reservations;

use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\UnitNight;
use App\Services\Folio\NightQuote;
use Brick\Money\Money;

/**
 * One night of a Change price plan. `charged` says its `night:{id}` line exists. `liveLines` are the top-level lines
 * of the night that are not reversed (room, inclusions, an earlier correction), to reverse before the new lines post.
 * `quote` is the night at the new price, and `key` the room line key of the correction (`night:{id}:fix:{n}`), with
 * `targets` the folio of each charge (null for the unit folio). `oldTotal` and `newTotal` are what the guest pays for
 * the night, with tax. See docs/12-reservations/09-change-night-price.md.
 */
class PlannedNightPrice
{
    /**
     * @param  list<FolioLine>  $liveLines
     * @param  array<string, Folio|null>  $targets
     */
    public function __construct(
        public readonly UnitNight $night,
        public readonly bool $charged,
        public readonly bool $hadDiscount,
        public readonly array $liveLines,
        public readonly NightQuote $quote,
        public readonly ?string $key,
        public readonly array $targets,
        public readonly Money $oldTotal,
        public readonly Money $newTotal,
    ) {}
}
