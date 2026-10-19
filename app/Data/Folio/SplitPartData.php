<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * One part of a split line: the folio it goes to and its amount (the entered amount, with tax calculated on it).
 */
class SplitPartData extends Data
{
    public function __construct(
        public int $folioId,
        public string $amount,
    ) {}
}
