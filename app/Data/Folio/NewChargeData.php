<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One manual charge to post on a unit. Give `chargeItemId` for a catalogue charge. For an open amount leave it null
 * and give `transactionCodeId` and `description` (an empty description falls back to the code name).
 * `unitAmount` is a decimal string. `idempotencyKey` is `manual:{uuid}`, sent by the form; null makes a fresh key.
 * With `unitAddOnId` the key is always `addon:{id}` and the caller passes that add-on's item, quantity and amount.
 * See docs/13-folio/02-posting-charges.md.
 */
class NewChargeData extends Data
{
    public function __construct(
        public ?int $chargeItemId,
        public ?int $transactionCodeId,
        public ?string $description,
        public int $quantity,
        public string $unitAmount,
        public ?string $idempotencyKey = null,
        public ?int $unitAddOnId = null,
    ) {}
}
