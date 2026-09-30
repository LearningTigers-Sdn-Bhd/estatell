<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** One stay length of a preview row: the total after discount, the discount, and each night's amount per unit. */
class StayDiscountPreviewStayData extends Data
{
    /**
     * @param  list<string>  $nightAmounts
     */
    public function __construct(
        public int $nights,
        public string $total,
        public string $discount,
        public array $nightAmounts,
    ) {}
}
