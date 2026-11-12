<?php

namespace App\Data\Commissions;

use App\Data\Property\CurrencyData;
use Spatie\LaravelData\Data;

class CommissionTotalsData extends Data
{
    public function __construct(
        public string $cashLiability,
        public string $immediatePayable,
        /** The payout date the immediate payable is due on, `Y-m-d`. */
        public string $payableOn,
        public string $owedToEstatell,
        public int $propertiesOwing,
        public CurrencyData $currency,
    ) {}
}
