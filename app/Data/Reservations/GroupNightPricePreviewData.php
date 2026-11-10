<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The preview of the group Change price Dialog: one line per picked unit, the total difference with tax (null until
 * a price is typed, or while a unit is refused), and whether a night had a discount.
 * See docs/12-reservations/09-change-night-price.md#change-the-price-of-a-group.
 */
class GroupNightPricePreviewData extends Data
{
    /**
     * @param  list<GroupNightPricePreviewLineData>  $lines
     */
    public function __construct(
        public array $lines,
        public ?string $difference,
        public bool $hadDiscount,
    ) {}
}
