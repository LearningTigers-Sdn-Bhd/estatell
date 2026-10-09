<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The Collect balance choices of the check-out form: the method, its reference and the amount the Dialog showed.
 * The amount is recorded as it is, and check-out then needs the unit folio at 0, so an amount that no longer settles
 * it (a charge posted while the form was open) refuses the whole check-out. `idempotencyKey` is the form's UUID, and
 * the payment uses `{uuid}:collect`.
 */
class CheckOutCollectData extends Data
{
    public function __construct(
        public int $paymentMethodId,
        public string $amount,
        public ?string $reference,
        public string $idempotencyKey,
    ) {}
}
