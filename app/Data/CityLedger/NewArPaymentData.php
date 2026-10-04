<?php

namespace App\Data\CityLedger;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * What the Record payment Dialog sends. `receivedOn` empty means the business date. `idempotencyKey` is the form's
 * UUID, so a repeat returns the same payment.
 */
class NewArPaymentData extends Data
{
    public function __construct(
        public int $paymentMethodId,
        public string $amount,
        public string $idempotencyKey,
        public ?CarbonImmutable $receivedOn = null,
        public ?string $reference = null,
        public ?string $notes = null,
    ) {}
}
