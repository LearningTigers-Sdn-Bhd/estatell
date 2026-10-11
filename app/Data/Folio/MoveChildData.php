<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * A tax or discount line under a charge on the Move charges page. It always moves with its charge.
 */
class MoveChildData extends Data
{
    public function __construct(
        public string $description,
        public string $amount,
    ) {}
}
