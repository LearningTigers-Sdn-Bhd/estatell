<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The choices of Collect now at check-in: which payments to take, the method and its reference. `roomCharges` takes the
 * unit's full bill as one Advance in place of the tax payment (`taxes` is then ignored). `idempotencyKey` is a UUID from
 * the form. The bill uses `{uuid}:bill`, the tax payment `{uuid}:taxes` and the deposit `{uuid}:deposit`, so a repeat of
 * the whole request returns the payments that exist. There are no amounts: the server works them out.
 */
class CollectNowData extends Data
{
    public function __construct(
        public bool $taxes,
        public bool $deposit,
        public int $paymentMethodId,
        public ?string $reference,
        public string $idempotencyKey,
        public bool $roomCharges = false,
    ) {}
}
