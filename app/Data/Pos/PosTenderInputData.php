<?php

namespace App\Data\Pos;

use App\Enums\PosTenderType;
use Spatie\LaravelData\Data;

/**
 * One way the guest pays. `amount` is the part of the bill it covers, before cash rounding. Cash may give the amount handed
 * over (`tenderedAmount`), which sets the change. A room charge names the stay (`reservationUnitId`). Other tenders use the
 * outlet's payment method of that type unless `paymentMethodId` picks one.
 */
class PosTenderInputData extends Data
{
    public function __construct(
        public PosTenderType $tenderType,
        public string $amount,
        public ?int $paymentMethodId = null,
        public ?string $tenderedAmount = null,
        public ?int $reservationUnitId = null,
        public ?string $reference = null,
    ) {}
}
