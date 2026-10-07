<?php

namespace App\Services\Folio;

use App\Models\TransactionCode;
use App\Services\Catalogues\TaxCalculationResult;
use Brick\Money\Money;

/**
 * One charge of a quoted night with its discount children and its tax. `unitAmount` is the amount before discounts and
 * `lineAmount` is what the charge line holds: `unitAmount` without the inclusive tax of the discounted amount. The
 * tax is calculated on the amount after the discounts, so the folio total is that amount plus any exclusive tax.
 */
class QuotedCharge
{
    /**
     * @param  list<QuotedDiscount>  $discounts
     */
    public function __construct(
        public readonly string $key,
        public readonly TransactionCode $code,
        public readonly string $description,
        public readonly Money $unitAmount,
        public readonly Money $lineAmount,
        public readonly array $discounts,
        public readonly TaxCalculationResult $tax,
    ) {}
}
