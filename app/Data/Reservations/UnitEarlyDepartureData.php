<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The early departure block of the Check out form, sent only when the unit leaves before its departure date. `fee` is
 * the policy fee before tax and `feeTotal` is what it adds to the unit folio with tax. Both are read only text: the
 * form sends `expected_fee` back, never an amount.
 */
class UnitEarlyDepartureData extends Data
{
    public function __construct(
        public string $departure,
        public int $nightsRemoved,
        public string $fee,
        public string $feeTotal,
        public bool $withinFreeWindow,
    ) {}
}
