<?php

namespace App\Data\Commissions;

use App\Data\Property\CurrencyData;
use Spatie\LaravelData\Data;

/**
 * The two cards at the top of the property Payouts page. Amounts are signed from the property's side: negative means
 * the property owes Estatell.
 */
class PropertyPayoutSummaryData extends Data
{
    public function __construct(
        public string $nextAmount,
        /** The payout date of the next payout, `Y-m-d`. */
        public string $nextDueOn,
        public ?string $lastAmount,
        public ?string $lastPaidOn,
        public ?string $lastReference,
        public CurrencyData $currency,
    ) {}
}
