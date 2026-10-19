<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * A saved old folio line, shown read only. `amount` is the signed line amount: a payment is negative.
 */
class OldFolioLineData extends Data
{
    public function __construct(
        public int $id,
        public string $serviceDate,
        public string $item,
        public string $description,
        public int $quantity,
        public string $amount,
    ) {}
}
