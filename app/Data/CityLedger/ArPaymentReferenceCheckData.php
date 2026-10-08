<?php

namespace App\Data\CityLedger;

use Spatie\LaravelData\Data;

/**
 * The answer of the reference check in the Record AR payment Dialog: a warning text when the reference was already
 * recorded, else null. It never blocks the payment.
 */
class ArPaymentReferenceCheckData extends Data
{
    public function __construct(public ?string $warning) {}
}
