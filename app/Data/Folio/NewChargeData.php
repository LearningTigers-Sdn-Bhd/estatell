<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One manual charge to post on a unit. The folio form always gives `chargeItemId` for a catalogue charge. Internal
 * callers may leave it null and give `transactionCodeId` for an open amount. `description` replaces the line text; an
 * empty one falls back to the item name, or the code name.
 * `unitAmount` is a decimal string. `idempotencyKey` is `manual:{uuid}`, sent by the form; null makes a fresh key.
 * With `unitAddOnId` the key is always `addon:{id}` and the caller passes that add-on's item, quantity and amount.
 * With `folioId` the charge lands on that folio and routing is not used; without it `FolioRouter` picks the folio.
 * With `ownFolio` the charge lands on the unit's own Unit folio (made when the unit has none) and routing is not used.
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
        public ?int $folioId = null,
        public bool $ownFolio = false,
    ) {}
}
