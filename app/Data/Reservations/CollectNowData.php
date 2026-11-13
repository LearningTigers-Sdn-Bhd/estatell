<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * The choices of Collect now at check-in: which payments to take, the method and its reference. `roomCharges` takes the
 * unit's full bill as one Advance in place of the tax payment (`taxes` is then ignored). `idempotencyKey` is a UUID from
 * the form. The bill uses `{uuid}:bill`, the tax payment `{uuid}:taxes` and the deposit `{uuid}:deposit`, so a repeat of
 * the whole request returns the payments that exist. `taxIds` are the taxes ticked in the form; null takes every
 * tax Collect now offers. There are no amounts: the server works them out.
 */
class CollectNowData extends Data
{
    /**
     * @param  list<int>|null  $taxIds
     * @param  list<int>  $chargeCodeIds  The unit-folio charges ticked in the form (`CheckInCollectionData::$charges`).
     */
    public function __construct(
        public bool $taxes,
        public bool $deposit,
        public int $paymentMethodId,
        public ?string $reference,
        public string $idempotencyKey,
        public bool $roomCharges = false,
        public ?array $taxIds = null,
        public array $chargeCodeIds = [],
    ) {}
}
