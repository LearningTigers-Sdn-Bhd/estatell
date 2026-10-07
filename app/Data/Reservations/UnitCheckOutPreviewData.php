<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Check out form shows when it opens: the balance of the unit folio, the security deposit held (a credit)
 * and how much of it a refund would give back so the folio reaches 0. `balance` is the plain folio sum, so a held
 * deposit makes it negative. `owing` is what the guest still has to pay, `folioKey` opens the folio tab. `early` is
 * set when the unit leaves before its departure date, and the fee in it is not yet in `balance`.
 */
class UnitCheckOutPreviewData extends Data
{
    public function __construct(
        public string $balance,
        public string $owing,
        public string $depositHeld,
        public string $refundDefault,
        public ?string $depositMethod,
        public bool $canRefund,
        public ?string $folioKey,
        public ?string $error,
        public ?UnitEarlyDepartureData $early = null,
    ) {}

    public static function refused(string $error): self
    {
        return new self('0.00', '0.00', '0.00', '0.00', null, false, null, $error);
    }
}
