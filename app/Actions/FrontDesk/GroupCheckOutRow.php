<?php

namespace App\Actions\FrontDesk;

use App\Data\Reservations\CheckOutCollectData;

/**
 * One room of the group check-out and what the front desk chose for its money. At most one choice is set: `collect`
 * (a Settlement payment on the room's folio, or its share of One payment for all), `refundAmount` (the part of the
 * credit to give back) or `addToMaster` (move the room's open charges to the Master folio). No choice is a room with
 * nothing to settle. `idempotencyKey` is the UUID of the save: a refund uses `{uuid}:{payment id}`, and the collect
 * data carries `{uuid}:{unit}` so its payment uses `{uuid}:{unit}:collect`.
 * See docs/18-front-desk/05-group-check-out.md.
 */
class GroupCheckOutRow
{
    public function __construct(
        public readonly int $unitId,
        public readonly string $idempotencyKey,
        public readonly ?CheckOutCollectData $collect = null,
        public readonly ?string $refundAmount = null,
        public readonly bool $addToMaster = false,
    ) {}
}
