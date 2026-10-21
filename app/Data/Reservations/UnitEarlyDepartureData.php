<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The early departure block of the Check out form, sent only when the unit leaves before its departure date. `fee` is
 * the policy fee before tax and `feeTotal` is what it adds to the unit folio with tax. Both are read only text: the
 * form sends `expected_fee` back, never an amount. `newDeparture` (`Y-m-d`) is the departure the check-out sets: the stay
 * day, or the business date for Guest actually left yesterday, and `leftYesterdayAvailable` says whether that choice is
 * open (the audit is pending and yesterday's night is not charged yet).
 */
class UnitEarlyDepartureData extends Data
{
    public function __construct(
        public string $departure,
        public int $nightsRemoved,
        public string $fee,
        public string $feeTotal,
        public bool $withinFreeWindow,
        public ?string $newDeparture = null,
        public bool $leftYesterdayAvailable = false,
    ) {}
}
