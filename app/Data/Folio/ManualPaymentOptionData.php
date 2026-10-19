<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * A payment a Refund row can pick: a completed payment of the reservation with money left to give back. `folioId`
 * is the folio it was taken on, which is where its refund posts.
 */
class ManualPaymentOptionData extends Data
{
    public function __construct(
        public int $id,
        public int $folioId,
        public string $label,
        public string $left,
    ) {}
}
